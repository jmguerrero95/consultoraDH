# ---------------------------------------------------------------------------
# Consultora DH - document conversion service
#
# A small HTTP service that converts Office documents using LibreOffice headless.
#
# ## Why it is a separate service and not a library in the PHP container
#
# LibreOffice is a ~500MB office suite with its own user profile, its own font
# caches and its own opinions about what a document means. Putting it in the
# application image would grow every app and queue worker by that much, for a
# capability only the document pipeline uses.
#
# More importantly it is a parser of untrusted input. Office files are zip
# archives containing XML, macros and external references, and a bug in any of
# that is a bug in the process running it. Running it in its own container means
# a compromise there is contained: this service holds one shared directory and
# has no database, no credentials, no network route to anything but the
# application, and no access to the application's memory.
#
# ## The isolation this service actually has
#
#   - It never runs as root.
#   - It has its own user profile in a scratch directory, discarded per call.
#   - Macros are disabled (`MacroSecurityLevel=3`, the highest).
#   - External resource retrieval is disabled
#     (`DisableRemoteContent`/`UpdateDocMode`), so a document cannot phone home
#     or fetch a remote template while it is being read.
#   - Reads one file from the shared directory, writes one file back.
#   - The application enforces the wall-clock timeout and the size cap; this
#     service enforces its own too, because a service that trusts its caller to
#     bound it is not a boundary.
# ---------------------------------------------------------------------------
FROM debian:bookworm-slim

# LibreOffice core plus the two filters this application needs: Writer for
# DOCX and Calc for XLSX. `--no-install-recommends` keeps the base image free of
# the desktop integration packages, editors and language packs that are never
# invoked headless.
RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        libreoffice-writer \
        libreoffice-calc \
        libreoffice-core \
        fonts-dejavu-core \
        fonts-liberation \
        ca-certificates \
        python3 \
        tini \
    && rm -rf /var/lib/apt/lists/*

# The unprivileged identity.
#
# The shared volume is created by the application container, which owns it as
# `www-data` (uid/gid 33). A converter running as any other uid could read the
# documents it was handed but not write its output back, which presents as a
# conversion failure and is actually a permission one.
#
# So the existing `www-data` account is reused rather than a new one created:
# gid 33 is already taken in this base image, so `groupadd --gid 33` fails, and
# inventing a different id reintroduces the problem. The service still runs as
# an unprivileged user with no root, and can actually complete the exchange the
# service exists for.
RUN mkdir -p /work /scratch \
    && chown -R www-data:www-data /work /scratch

COPY --chown=www-data:www-data docker/converter/service.py /opt/converter/service.py

USER www-data
WORKDIR /work

ENV PYTHONUNBUFFERED=1 \
    HOME=/home/www-data \
    SCRATCH=/scratch

EXPOSE 8080

# The health check is the converter's own, so the application can refuse to
# offer a conversion it cannot perform rather than failing each request.
HEALTHCHECK --interval=15s --timeout=5s --start-period=30s --retries=5 \
    CMD python3 -c "import urllib.request,sys; sys.exit(0 if urllib.request.urlopen('http://127.0.0.1:8080/healthz', timeout=4).status == 200 else 1)"

# tini reaps the LibreOffice child. Without it a crashed conversion leaves a
# zombie per call, and the container eventually holds thousands of them.
ENTRYPOINT ["/usr/bin/tini", "--"]
CMD ["python3", "/opt/converter/service.py"]