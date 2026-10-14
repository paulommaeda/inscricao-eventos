#!/usr/bin/env python3
"""Build the installable WordPress ZIP without development dependencies."""
from pathlib import Path
from zipfile import ZipFile, ZIP_DEFLATED

root = Path(__file__).resolve().parents[1]
dest = root / 'dist'
dest.mkdir(exist_ok=True)
out = dest / 'inscricao-eventos-1.2.0.zip'
skip = {'tests', 'scripts', 'dist', 'node_modules', '.git', '.github', 'validation'}
with ZipFile(out, 'w', ZIP_DEFLATED) as archive:
    for file in sorted(root.rglob('*')):
        if not file.is_file():
            continue
        rel = file.relative_to(root)
        if rel.parts[0] in skip or rel.parts[0].startswith('.') or rel.name in {'package.json', 'package-lock.json', 'AGENTS.md'}:
            continue
        archive.write(file, Path('inscricao-eventos') / rel)
print(out)
