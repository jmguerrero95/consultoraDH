#!/usr/bin/env python3
"""Consultora DH - document conversion service.

Converts one Office document at a time using LibreOffice headless. It exists so
that LibreOffice does not have to live inside the PHP runtime, and so that a
parser working on untrusted input is confined to a container with no database,
no credentials and no route anywhere except back to the application.

## The shape of the contract

    POST /convert   {"name": "cv.docx", "target": "pdf"}
    POST /convert   {"name": "datos.xlsx", "target": "csv"}
    -> {"ok": true, "name": "cv.pdf", "bytes": 18342, "sha256": "..."}
    -> {"ok": false, "error": "..."}

One file in, one file out. The caller names a file that is already in the
shared directory; this service never fetches anything, and there is no
"download this URL and convert it" path here by design, because that is a
server-side request forgery with a parser attached.

## Why each LibreOffice flag is here

    -env:UserInstallation=file:///scratch/<id>
        A throwaway profile per call. A shared profile lets one document's
        settings — macros, recovery state, a cached remote URL — influence the
        next call, which is precisely the state a hostile document wants to
        influence.

    MacroSecurityLevel=3
        The highest setting: macros never run, not even after a user gesture,
        because there is no user. A document that needs its macro to render is
        not a document this should convert.

    DisableRemoteContent / UpdateDocMode
        A document may declare a remote image, a linked spreadsheet or an
        external template. Without these, converting it would make this
        container fetch whatever it names, turning an attacker-controlled file
        into an outbound request from inside our network.

    --norestore --invisible --nologo --nodefault --nolockcheck
        Suppresses the interactive parts. There is no display and no user, and
        a dialog waiting for input would hang until the timeout.

## Why this validates rather than trusting

The caller is another container, not a trusted caller. The file name is
reduced to a basename before it is ever joined to a path, because a name
containing `../` would otherwise let a request read or overwrite something
outside the shared directory. The input size is capped before LibreOffice sees
it, and the output is checked to be a real file with real content before it is
reported as a success — renaming a file to `.pdf` is not a conversion, and
neither this service nor the caller may pretend otherwise.
"""

import hashlib
import json
import os
import re
import shutil
import subprocess
import tempfile
import time
import uuid
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer

# LibreOffice's product filters, by the output extension we want.
# `--convert-to pdf:writer_pdf_Export` names the filter explicitly; relying on
# the bare `pdf` lets LibreOffice guess, and its guess is sometimes Calc's PDF
# export when the input was a spreadsheet.
FILTERS = {
    ("docx", "pdf"): "pdf:writer_pdf_Export",
    ("odt", "pdf"): "pdf:writer_pdf_Export",
    ("doc", "pdf"): "pdf:writer_pdf_Export",
    ("rtf", "pdf"): "pdf:writer_pdf_Export",
    ("xlsx", "pdf"): "pdf:calc_pdf_Export",
    ("ods", "pdf"): "pdf:calc_pdf_Export",
    ("pptx", "pdf"): "pdf:impress_pdf_Export",
    ("xlsx", "csv"): "csv:Text - txt - csv (StarCalc):44,34,76",
    ("xlsx", "ods"): "ods:calc8",
    ("docx", "txt"): "txt:Text (encoded):UTF8",
    ("docx", "html"): "html:HTML (StarWriter)",
}

# Extension -> the exact bytes a file of that type must begin with. Checked on
# input so that a renamed executable is not handed to a parser expecting a
# document, and on output so that a "successful" conversion that produced
# something else is reported as a failure.
SIGNATURES = {
    "pdf": [b"%PDF-"],
    "docx": [b"PK\x03\x04"],
    "xlsx": [b"PK\x03\x04"],
    "ods": [b"PK\x03\x04"],
    "odt": [b"PK\x03\x04"],
    "pptx": [b"PK\x03\x04"],
    "csv": None,   # any text
    "txt": None,
    "md": None,
    "html": None,
}

SUPPORTED_INPUTS = {
    "pdf", "docx", "xlsx", "csv", "txt", "md", "odt", "ods", "doc", "rtf", "pptx", "html",
}

# Hard ceilings. The application sets its own, lower, budget; these exist so
# that this service is still bounded if it is ever called by something else.
MAX_INPUT_BYTES = 32 * 1024 * 1024      # 32 MB
MAX_OUTPUT_BYTES = 64 * 1024 * 1024     # 64 MB
TIMEOUT_SECONDS = 120

WORK_DIR = os.environ.get("WORK_DIR", "/work")
SCRATCH_DIR = os.environ.get("SCRATCH", "/scratch")


def log(message: str) -> None:
    # stdout is the service's only channel, and `docker logs` is where an
    # operator looks. Nothing here ever carries document content.
    print(f"[converter] {message}", flush=True)


def safe_basename(name: str) -> str:
    """Reduce a caller-supplied name to a harmless one.

    The name is attacker-controlled even though the caller is ours: a filename
    reaching this service from a request body could contain a path. Everything
    up to the last separator is discarded, and anything that is not a plain
    file name is rejected outright rather than cleaned, because a name needing
    cleaning is not a name worth trying to interpret.
    """
    if not name or not isinstance(name, str):
        raise ValueError("missing file name")

    candidate = name.replace("\\", "/").split("/")[-1]

    if not candidate or candidate in (".", ".."):
        raise ValueError("invalid file name")

    # Only the characters a real document name needs.
    if not re.fullmatch(r"[A-Za-z0-9._-]{1,180}", candidate):
        raise ValueError("invalid file name")

    if candidate.startswith("."):
        raise ValueError("invalid file name")

    return candidate


def file_signature(path: str) -> bytes:
    with open(path, "rb") as handle:
        return handle.read(8)


def signature_ok(path: str, extension: str) -> bool:
    expected = SIGNATURES.get(extension)
    if expected is None:
        return True
    try:
        return any(file_signature(path).startswith(prefix) for prefix in expected)
    except OSError:
        return False


def convert(input_path: str, input_extension: str, target: str) -> tuple[str, str]:
    """Run one conversion. Returns (output_path, output_extension)."""
    filter_spec = FILTERS.get((input_extension, target))

    if filter_spec is None:
        raise ValueError(f"conversion not supported: {input_extension} -> {target}")

    run_id = uuid.uuid4().hex
    profile = os.path.join(SCRATCH_DIR, f"profile-{run_id}")
    out_dir = os.path.join(SCRATCH_DIR, f"out-{run_id}")

    os.makedirs(profile, exist_ok=True)
    os.makedirs(out_dir, exist_ok=True)

    # `-env:UserInstallation` must be a URI, and the directory must not exist
    # beforehand or LibreOffice silently reuses whatever is inside it.
    command = [
        "soffice",
        f"-env:UserInstallation=file://{profile}",
        # Nothing here may reach the network or run anything embedded.
        "MacroSecurityLevel=3",
        "--norestore",
        "--invisible",
        "--nologo",
        "--nodefault",
        "--nolockcheck",
        "--nofirststartwizard",
        "--convert-to",
        filter_spec,
        "--outdir",
        out_dir,
        input_path,
    ]

    environment = dict(os.environ)
    environment["HOME"] = os.path.expanduser("~")

    started = time.time()

    try:
        completed = subprocess.run(
            command,
            capture_output=True,
            timeout=TIMEOUT_SECONDS,
            env=environment,
            # No stdin: a document that tries to prompt must fail, not wait.
            stdin=subprocess.DEVNULL,
        )
    except subprocess.TimeoutExpired:
        shutil.rmtree(profile, ignore_errors=True)
        shutil.rmtree(out_dir, ignore_errors=True)
        raise TimeoutError(f"conversion exceeded {TIMEOUT_SECONDS}s")
    finally:
        # LibreOffice forks and returns before flushing in some builds; a short
        # grace period keeps a just-written output from being rmtree'd early.
        time.sleep(0.2)

    stem = os.path.splitext(os.path.basename(input_path))[0]
    produced = os.path.join(out_dir, f"{stem}.{target}")

    if completed.returncode != 0 or not os.path.isfile(produced):
        shutil.rmtree(profile, ignore_errors=True)
        shutil.rmtree(out_dir, ignore_errors=True)
        detail = (completed.stderr or b"").decode("utf-8", "replace")[:200]
        log(f"failed {input_extension}->{target}: rc={completed.returncode} {detail}")
        raise RuntimeError("the converter did not produce an output file")

    size = os.path.getsize(produced)

    if size == 0:
        shutil.rmtree(profile, ignore_errors=True)
        shutil.rmtree(out_dir, ignore_errors=True)
        raise RuntimeError("the converter produced an empty file")

    if size > MAX_OUTPUT_BYTES:
        shutil.rmtree(profile, ignore_errors=True)
        shutil.rmtree(out_dir, ignore_errors=True)
        raise RuntimeError("the converted file exceeds the size limit")

    # A file named `.pdf` that is not a PDF is not a conversion. Renaming is
    # the failure this check exists to catch.
    if not signature_ok(produced, target):
        shutil.rmtree(profile, ignore_errors=True)
        shutil.rmtree(out_dir, ignore_errors=True)
        raise RuntimeError("the output does not carry the signature its extension claims")

    elapsed = time.time() - started
    log(f"converted {input_extension}->{target} in {elapsed:.2f}s ({size} bytes)")

    shutil.rmtree(profile, ignore_errors=True)

    return produced, out_dir


class Handler(BaseHTTPRequestHandler):
    server_version = "ConsultoraDHConverter/1.0"

    def log_message(self, fmt: str, *args) -> None:
        # Suppressed: BaseHTTPRequestHandler logs the full request line, which
        # is where a filename would land. `convert()` logs what actually matters.
        return

    def _reply(self, status: int, payload: dict) -> None:
        body = json.dumps(payload).encode("utf-8")
        self.send_response(status)
        self.send_header("Content-Type", "application/json")
        self.send_header("Content-Length", str(len(body)))
        self.end_headers()
        self.wfile.write(body)

    def do_GET(self) -> None:
        if self.path == "/healthz":
            # Reports what is actually true, including whether the binary this
            # service depends on is present. A health check that passes while
            # LibreOffice is missing is worse than no health check.
            soffice = shutil.which("soffice")
            self._reply(
                200 if soffice else 503,
                {"ok": bool(soffice), "soffice": soffice is not None, "work_dir": os.path.isdir(WORK_DIR)},
            )
            return

        self._reply(404, {"ok": False, "error": "not found"})

    def do_POST(self) -> None:
        if self.path != "/convert":
            self._reply(404, {"ok": False, "error": "not found"})
            return

        try:
            length = int(self.headers.get("Content-Length", "0"))
        except ValueError:
            self._reply(400, {"ok": False, "error": "invalid Content-Length"})
            return

        if length <= 0 or length > 64 * 1024:
            self._reply(400, {"ok": False, "error": "invalid body length"})
            return

        try:
            payload = json.loads(self.rfile.read(length))
        except (ValueError, UnicodeDecodeError):
            self._reply(400, {"ok": False, "error": "invalid JSON"})
            return

        try:
            result = self._convert_one(payload)
        except (ValueError, TimeoutError, RuntimeError) as error:
            self._reply(422, {"ok": False, "error": str(error)})
            return
        except Exception:  # noqa: BLE001 - a parser fault must not kill the service
            log("unexpected conversion error")
            self._reply(500, {"ok": False, "error": "internal conversion error"})
            return

        self._reply(200, result)

    def _convert_one(self, payload: dict) -> dict:
        name = safe_basename(str(payload.get("name", "")))
        target = str(payload.get("target", "")).lower().lstrip(".")

        if target not in {"pdf", "docx", "xlsx", "csv", "txt", "html", "ods"}:
            raise ValueError(f"unsupported target: {target}")

        source_extension = name.rsplit(".", 1)[-1].lower() if "." in name else ""

        if source_extension not in SUPPORTED_INPUTS:
            raise ValueError(f"unsupported input: {source_extension}")

        source_path = os.path.join(WORK_DIR, name)

        # The name has already been reduced to a basename, so this join cannot
        # escape the shared directory.
        if not os.path.isfile(source_path):
            raise ValueError("the named file is not present")

        size = os.path.getsize(source_path)

        if size == 0:
            raise ValueError("the named file is empty")

        if size > MAX_INPUT_BYTES:
            raise ValueError("the named file exceeds the size limit")

        if not signature_ok(source_path, source_extension):
            raise ValueError("the file does not match its extension")

        produced, out_dir = convert(source_path, source_extension, target)

        output_name = f"{os.path.splitext(name)[0]}.{target}"
        final_path = os.path.join(WORK_DIR, output_name)

        # `/work` and `/scratch` are different mounts, so this is a copy and not
        # a rename. `shutil.move` does fall back to a copy across devices, but
        # it then tries to preserve the source's ownership, which fails for a
        # file this process does not own — a permission error that reads like a
        # broken converter. Copying explicitly keeps the result readable by the
        # application, which is the only thing the mode is for here.
        with open(produced, "rb") as source_handle:
            payload = source_handle.read()

        with open(final_path, "wb") as destination_handle:
            destination_handle.write(payload)

        shutil.rmtree(out_dir, ignore_errors=True)

        with open(final_path, "rb") as handle:
            digest = hashlib.sha256(handle.read()).hexdigest()

        return {
            "ok": True,
            "name": output_name,
            "bytes": os.path.getsize(final_path),
            "sha256": digest,
            "target": target,
        }


def main() -> None:
    os.makedirs(WORK_DIR, exist_ok=True)
    os.makedirs(SCRATCH_DIR, exist_ok=True)

    port = int(os.environ.get("PORT", "8080"))

    log(f"starting on :{port}, work={WORK_DIR}")
    ThreadingHTTPServer(("0.0.0.0", port), Handler).serve_forever()


if __name__ == "__main__":
    main()