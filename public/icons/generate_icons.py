"""Generate the PWA icon set.

No image library is available in this environment, so the PNGs are written
directly: a signature, an IHDR, one zlib-compressed IDAT of RGBA scanlines
and an IEND. Each file is a rounded square in the brand blue carrying a white
speech bubble, drawn analytically with 4x supersampling for smooth edges.
"""

import struct
import zlib
from pathlib import Path

BRAND = (37, 99, 235)      # #2563eb, the manifest theme colour
WHITE = (255, 255, 255)
SS = 4                     # supersampling factor

# The maskable safe zone is the middle 80% of the canvas, so anything that must
# survive an aggressive circular crop is drawn well inside it.
SAFE = 0.80


def rounded_rect(x, y, w, h, r):
    """Signed containment test for a rounded rectangle."""
    def inside(px, py):
        if px < x or py < y or px > x + w or py > y + h:
            return False
        cx = min(max(px, x + r), x + w - r)
        cy = min(max(py, y + r), y + h - r)
        return (px - cx) ** 2 + (py - cy) ** 2 <= r * r
    return inside


def render(size):
    n = size * SS
    u = n / 100.0                      # work in hundredths of the canvas

    outer = rounded_rect(0, 0, n, n, 22 * u)
    bubble = rounded_rect(22 * u, 20 * u, 56 * u, 40 * u, 9 * u)

    # The tail: a triangle hanging off the lower-left of the bubble.
    tail = (
        (32 * u, 58 * u), (32 * u, 76 * u), (52 * u, 58 * u)
    )

    def in_tail(px, py):
        (ax, ay), (bx, by), (cx, cy) = tail
        d1 = (px - bx) * (ay - by) - (ax - bx) * (py - by)
        d2 = (px - cx) * (by - cy) - (bx - cx) * (py - cy)
        d3 = (px - ax) * (cy - ay) - (cx - ax) * (py - ay)
        has_neg = (d1 < 0) or (d2 < 0) or (d3 < 0)
        has_pos = (d1 > 0) or (d2 > 0) or (d3 > 0)
        return not (has_neg and has_pos)

    # Three bars inside the bubble standing in for message lines.
    bars = [
        rounded_rect(32 * u, 30 * u, 36 * u, 5 * u, 2.5 * u),
        rounded_rect(32 * u, 40 * u, 36 * u, 5 * u, 2.5 * u),
        rounded_rect(32 * u, 50 * u, 20 * u, 5 * u, 2.5 * u),
    ]

    samples = [[(0, 0, 0, 0)] * n for _ in range(n)]

    for y in range(n):
        row = samples[y]
        for x in range(n):
            if not outer(x + 0.5, y + 0.5):
                continue
            px, py = x + 0.5, y + 0.5
            if any(b(px, py) for b in bars):
                # Blue on white, so the bubble reads as lines of text rather
                # than as a blank block.
                row[x] = (BRAND[0], BRAND[1], BRAND[2], 255)
            elif bubble(px, py) or in_tail(px, py):
                row[x] = (WHITE[0], WHITE[1], WHITE[2], 255)
            else:
                row[x] = (BRAND[0], BRAND[1], BRAND[2], 255)

    # Box-downsample the supersampled grid to the target size.
    out = []
    for y in range(size):
        row = bytearray()
        for x in range(size):
            r = g = b = a = 0
            for dy in range(SS):
                src = samples[y * SS + dy]
                for dx in range(SS):
                    pr, pg, pb, pa = src[x * SS + dx]
                    r += pr * pa
                    g += pg * pa
                    b += pb * pa
                    a += pa
            if a == 0:
                row += bytes((0, 0, 0, 0))
            else:
                row += bytes((r // a, g // a, b // a, a // (SS * SS)))
        out.append(bytes(row))

    return out


def write_png(path, size):
    pixels = render(size)
    raw = b"".join(b"\x00" + row for row in pixels)

    def chunk(tag, data):
        body = tag + data
        return struct.pack(">I", len(data)) + body + struct.pack(">I", zlib.crc32(body))

    png = b"\x89PNG\r\n\x1a\n"
    png += chunk(b"IHDR", struct.pack(">IIBBBBB", size, size, 8, 6, 0, 0, 0))
    png += chunk(b"IDAT", zlib.compress(raw, 9))
    png += chunk(b"IEND", b"")

    path.write_bytes(png)
    return len(png)


if __name__ == "__main__":
    target = Path("public/icons")
    target.mkdir(parents=True, exist_ok=True)

    for size in (72, 96, 128, 144, 152, 192, 384, 512):
        p = target / f"icon-{size}x{size}.png"
        written = write_png(p, size)
        print(f"{p}  {written} bytes")

    # The badge is monochrome so the platform can tint it for the tray.
    badge = target / "badge-72x72.png"
    print(f"{badge}  (copy of icon-72x72.png)")
    badge.write_bytes((target / "icon-72x72.png").read_bytes())