"""Builds dist/oaiq_chatgpt_feed.ocmod.zip (upload/ + install.xml) for the OpenCart 3 installer."""
import os
import zipfile

ROOT = os.path.dirname(os.path.abspath(__file__))
os.makedirs(os.path.join(ROOT, 'dist'), exist_ok=True)
target = os.path.join(ROOT, 'dist', 'oaiq_chatgpt_feed.ocmod.zip')

with zipfile.ZipFile(target, 'w', zipfile.ZIP_DEFLATED) as z:
    z.write(os.path.join(ROOT, 'install.xml'), 'install.xml')
    for base, _, files in os.walk(os.path.join(ROOT, 'upload')):
        for name in sorted(files):
            path = os.path.join(base, name)
            z.write(path, os.path.relpath(path, ROOT).replace(os.sep, '/'))

print(target)
