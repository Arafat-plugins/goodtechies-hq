#!/usr/bin/env python3
"""Make the extension's toolbar icons from the project mark.

Usage (from apps/timer-extension/):
    python3 scripts/make-icons.py ../../public/brand/icon-512.png

Writes src/icons/icon-16.png, icon-32.png, icon-48.png and icon-128.png (RGBA).
"""

import os
import sys

from PIL import Image

SIZES = (16, 32, 48, 128)
HERE = os.path.dirname(os.path.abspath(__file__))
OUT_DIR = os.path.join(HERE, '..', 'src', 'icons')
DEFAULT_SOURCE = os.path.join(HERE, '..', '..', '..', 'public', 'brand', 'icon-512.png')


def main() -> int:
    source = sys.argv[1] if len(sys.argv) > 1 else DEFAULT_SOURCE
    os.makedirs(OUT_DIR, exist_ok=True)
    with Image.open(source) as image:
        mark = image.convert('RGBA')
        for size in SIZES:
            target = os.path.join(OUT_DIR, f'icon-{size}.png')
            mark.resize((size, size), Image.LANCZOS).save(target, 'PNG')
            print(f'wrote {os.path.relpath(target)}')
    return 0


if __name__ == '__main__':
    sys.exit(main())
