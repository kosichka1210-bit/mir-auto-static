"""Build the actual site and guard the compact-card request without touching production."""
from pathlib import Path
import shutil
import subprocess
import sys
import tempfile
import zipfile

repo = Path(__file__).resolve().parents[1]
with tempfile.TemporaryDirectory(prefix='mir-auto-card-test-') as directory:
    root = Path(directory)
    with zipfile.ZipFile(repo / 'timeweb-site.zip') as bundle:
        bundle.extractall(root)
    subprocess.run([sys.executable, str(repo/'tools/enrich_site_seo.py'), str(root)], check=True)
    home=(root/'index.html').read_text(encoding='utf-8')
    source=(root/'live-catalog.js').read_text(encoding='utf-8')
    css=(root/'style.css').read_text(encoding='utf-8')
    assert '↗' not in home
    assert '<p class="car-specs">' not in source
    assert 'live-catalog.js?v=compact-cards-11' in home
    assert 'href="${detailsUrl}"' in source and 'Обсудить автомобиль' in source
    assert 'touch-action:manipulation' in css and 'display:none!important' in css
    assert 'Manrope,Arial,sans-serif' in css  # body text retains its existing font
    assert 'font-family:Unbounded,Manrope,Arial,sans-serif' in css
    assert "font-display:swap" in css
    assert (root/'fonts/unbounded-v1.ttf').stat().st_size > 10000
    assert 'style.css?v=unbounded-compact-12' in home
    php=(repo/'telegram-bot/public/api/catalogue.php').read_text(encoding='utf-8')
    assert 'car-specs' not in php and '$factsHtml' not in php
    assert '<h3>' in php and 'Обсудить автомобиль' in php
    node=shutil.which('node')
    if not node: raise RuntimeError('Node is required to verify the generated catalogue script')
    subprocess.run([node,'--check',str(root/'live-catalog.js')],check=True)
    print('Compact cards passed: no summaries/arrows; links preserved; self-hosted Unbounded headings, body unchanged; JS valid.')
