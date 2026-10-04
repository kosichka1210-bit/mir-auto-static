#!/usr/bin/env python3
"""Apply MIR AUTO's production SEO metadata to the unpacked static site."""

from __future__ import annotations

import html
import json
import re
import sys
import xml.etree.ElementTree as ET
from pathlib import Path
from urllib.parse import urljoin

SITE = "https://mir-auto-china.ru"
HOME_TITLE = "Автомобили из Китая в Санкт-Петербурге — подбор | MIR AUTO"
HOME_DESCRIPTION = (
    "Подбор авто из Китая для клиентов в Санкт-Петербурге. Помогаем выбрать модель, "
    "проверить автомобиль и сопроводить покупку и отправку в регион."
)
CATALOG_TITLE = "Каталог автомобилей из Китая под заказ — MIR AUTO"
CATALOG_DESCRIPTION = (
    "Опубликованные предложения MIR AUTO: модели, характеристики и цены для Владивостока. "
    "Консультации по подбору доступны клиентам в Санкт-Петербурге."
)
SPB_PARAGRAPH = (
    "Для клиентов в Санкт-Петербурге Владимир и Олег помогают обсудить подходящие модели "
    "и связаться с MIR AUTO для подбора автомобиля из Китая. Актуальную цену и условия "
    "по конкретному предложению представители подтверждают до покупки."
)


def replace_meta(html_text: str, attribute: str, key: str, content: str) -> str:
    pattern = re.compile(
        rf'<meta\s+[^>]*{attribute}=["\']{re.escape(key)}["\'][^>]*>', re.I
    )
    tag = f'<meta {attribute}="{html.escape(key, quote=True)}" content="{html.escape(content, quote=True)}">'
    if pattern.search(html_text):
        return pattern.sub(tag, html_text, count=1)
    return re.sub(r"</head\s*>", f"  {tag}\n</head>", html_text, count=1, flags=re.I)


def replace_canonical(html_text: str, canonical: str) -> str:
    pattern = re.compile(r'<link\s+[^>]*rel=["\']canonical["\'][^>]*>', re.I)
    tag = f'<link rel="canonical" href="{html.escape(canonical, quote=True)}">'
    if pattern.search(html_text):
        return pattern.sub(tag, html_text, count=1)
    return re.sub(r"</head\s*>", f"  {tag}\n</head>", html_text, count=1, flags=re.I)


def set_title(html_text: str, title: str) -> str:
    tag = f"<title>{html.escape(title)}</title>"
    if re.search(r"<title\b[^>]*>.*?</title\s*>", html_text, re.I | re.S):
        return re.sub(r"<title\b[^>]*>.*?</title\s*>", tag, html_text, count=1, flags=re.I | re.S)
    return re.sub(r"</head\s*>", f"  {tag}\n</head>", html_text, count=1, flags=re.I)


def set_structured_data(html_text: str, graph: list[dict]) -> str:
    html_text = re.sub(
        r'<script\s+type=["\']application/ld\+json["\']\s+id=["\']mir-auto-structured-data["\']\s*>.*?</script\s*>',
        "",
        html_text,
        count=1,
        flags=re.I | re.S,
    )
    data = json.dumps({"@context": "https://schema.org", "@graph": graph}, ensure_ascii=False)
    data = data.replace("</", "<\\/")
    tag = f'<script type="application/ld+json" id="mir-auto-structured-data">{data}</script>'
    return re.sub(r"</head\s*>", f"  {tag}\n</head>", html_text, count=1, flags=re.I)


def enrich_images(html_text: str) -> str:
    def update(match: re.Match[str]) -> str:
        tag = match.group(0)
        if re.search(r"\bclass=[\"'][^\"']*\bhero-image\b", tag, re.I):
            for name, value in (("fetchpriority", "high"), ("decoding", "async")):
                if not re.search(rf"\b{name}\s*=", tag, re.I):
                    tag = tag[:-1] + f' {name}="{value}">'
        elif "/images/cars/" in tag:
            for name, value in (("loading", "lazy"), ("decoding", "async")):
                if not re.search(rf"\b{name}\s*=", tag, re.I):
                    tag = tag[:-1] + f' {name}="{value}">'
        alt = re.search(r"\balt=[\"']([^\"']*)[\"']", tag, re.I)
        if alt is None or not alt.group(1).strip():
            label = "Автомобиль из Китая — MIR AUTO" if "/images/cars/" in tag else ""
            if alt is None:
                tag = tag[:-1] + f' alt="{html.escape(label, quote=True)}">'
            else:
                tag = tag[:alt.start(1)] + html.escape(label, quote=True) + tag[alt.end(1):]
        return tag

    return re.sub(r"<img\b[^>]*>", update, html_text, flags=re.I)


def absolute_car_image(html_text: str, page_url: str) -> tuple[str, str]:
    for tag in re.findall(r"<img\b[^>]*>", html_text, flags=re.I):
        if "/images/cars/" not in tag:
            continue
        src = re.search(r"\bsrc=[\"']([^\"']+)[\"']", tag, re.I)
        alt = re.search(r"\balt=[\"']([^\"']*)[\"']", tag, re.I)
        if src:
            return urljoin(page_url, html.unescape(src.group(1))), html.unescape(alt.group(1)) if alt else "Автомобиль из Китая — MIR AUTO"
    return f"{SITE}/og.png", "Автомобили из Китая — MIR AUTO"


def page_graph(kind: str, title: str, description: str, canonical: str, image: str, year: str | None = None) -> list[dict]:
    breadcrumb = {
        "@type": "BreadcrumbList",
        "itemListElement": [
            {"@type": "ListItem", "position": 1, "name": "Главная", "item": f"{SITE}/"},
            {"@type": "ListItem", "position": 2, "name": "Автомобили", "item": f"{SITE}/avtomobili/"},
        ],
    }
    if kind == "home":
        return [
            {"@type": "Organization", "name": "MIR AUTO", "url": f"{SITE}/", "logo": f"{SITE}/images/mir-auto-logo.jpg", "sameAs": ["https://t.me/mirautochina125", "https://max.ru/join/r2j2F1wbOH7K1SZlTK6gUjW1LZMy6af-BQVYi8O0I14"]},
            {"@type": "WebSite", "name": "MIR AUTO", "url": f"{SITE}/", "inLanguage": "ru-RU"},
        ]
    if kind == "catalog":
        breadcrumb["itemListElement"].append({"@type": "ListItem", "position": 3, "name": "Каталог автомобилей", "item": canonical})
        return [{"@type": "CollectionPage", "name": title, "description": description, "url": canonical, "inLanguage": "ru-RU"}, breadcrumb]
    breadcrumb["itemListElement"].append({"@type": "ListItem", "position": 3, "name": title, "item": canonical})
    car = {"@type": "Car", "name": title, "description": description, "url": canonical, "image": image, "inLanguage": "ru-RU"}
    if year:
        car["vehicleModelDate"] = year
    return [car, breadcrumb]


def enrich(page: Path, root: Path) -> None:
    relative = page.relative_to(root).as_posix()
    text = page.read_text(encoding="utf-8")
    if relative == "index.html":
        kind = "home"
        title, description, canonical = HOME_TITLE, HOME_DESCRIPTION, f"{SITE}/"
        image = f"{SITE}/og.png"
        if SPB_PARAGRAPH not in text:
            anchor = "<p>Если нужной модели нет в витрине, представители подберут автомобиль под ваш бюджет и пожелания и проконсультируют по подходящим вариантам.</p>"
            if anchor in text:
                text = text.replace(anchor, anchor + f"<p>{SPB_PARAGRAPH}</p>", 1)
    elif relative == "avtomobili/index.html":
        kind = "catalog"
        title, description, canonical = CATALOG_TITLE, CATALOG_DESCRIPTION, f"{SITE}/avtomobili/"
        image = f"{SITE}/og.png"
    elif relative == "avtomobili/avtomobil/index.html":
        kind = "dynamic-template"
        title = "Автомобиль из каталога — MIR AUTO"
        description = "Страница автомобиля из каталога MIR AUTO. Откройте опубликованное предложение, чтобы посмотреть характеристики и фотографии."
        canonical = f"{SITE}/avtomobili/"
        image = f"{SITE}/og.png"
    elif relative.startswith("avtomobili/") and relative.endswith("/index.html"):
        kind = "car"
        current_title = re.search(r"<title\b[^>]*>(.*?)</title\s*>", text, re.I | re.S)
        current_title_text = html.unescape(re.sub(r"\s+", " ", current_title.group(1))).strip() if current_title else "Автомобиль MIR AUTO"
        name = current_title_text.split(" из Китая")[0].strip()
        title = f"{name} из Китая — характеристики | MIR AUTO"
        description = f"{name} из опубликованного предложения MIR AUTO: характеристики и фотографии. Актуальность, цену и условия заказа уточняйте у представителей."
        canonical = f"{SITE}/{relative.removesuffix('index.html')}"
        page_url = f"{SITE}/{relative.removesuffix('index.html')}"
        image, image_alt = absolute_car_image(text, page_url)
        text = replace_meta(text, "name", "description", description)
        text = replace_meta(text, "property", "og:image:alt", image_alt)
        year_match = re.search(r"\b(19\d{2}|20\d{2})\b", name)
        graph = page_graph("car", title, description, canonical, image, year_match.group(1) if year_match else None)
        text = set_structured_data(text, graph)
    else:
        return

    text = set_title(text, title)
    text = replace_meta(text, "name", "description", description)
    text = replace_canonical(text, canonical)
    text = replace_meta(text, "property", "og:type", "website")
    text = replace_meta(text, "property", "og:site_name", "MIR AUTO")
    text = replace_meta(text, "property", "og:title", title)
    text = replace_meta(text, "property", "og:description", description)
    text = replace_meta(text, "property", "og:url", canonical)
    text = replace_meta(text, "property", "og:image", image)
    text = replace_meta(text, "property", "og:image:alt", "Автомобили из Китая и предложения MIR AUTO")
    text = replace_meta(text, "property", "og:locale", "ru_RU")
    text = replace_meta(text, "name", "twitter:card", "summary_large_image")
    text = enrich_images(text)
    if kind == "home":
        text = set_structured_data(text, page_graph("home", title, description, canonical, image))
    elif kind == "catalog":
        text = set_structured_data(text, page_graph("catalog", title, description, canonical, image))
    elif kind == "dynamic-template":
        text = replace_meta(text, "name", "robots", "noindex,follow")

    page.write_text(text, encoding="utf-8", newline="")


def update_sitemap(root: Path) -> None:
    sitemap_path = root / "sitemap.xml"
    ns = "http://www.sitemaps.org/schemas/sitemap/0.9"
    ET.register_namespace("", ns)
    existing: set[str] = set()
    if sitemap_path.exists():
        tree = ET.parse(sitemap_path)
        for location in tree.findall(f".//{{{ns}}}loc"):
            if location.text:
                existing.add(location.text.strip())
    static_urls = {f"{SITE}/", f"{SITE}/avtomobili/"}
    for page in root.glob("avtomobili/*/index.html"):
        if page.parent.name != "avtomobil":
            static_urls.add(f"{SITE}/avtomobili/{page.parent.name}/")
    urls = sorted(existing | static_urls)
    urlset = ET.Element(f"{{{ns}}}urlset")
    for url in urls:
        ET.SubElement(urlset, f"{{{ns}}}url").append(ET.Element(f"{{{ns}}}loc"))
        urlset[-1][0].text = url
    ET.ElementTree(urlset).write(sitemap_path, encoding="utf-8", xml_declaration=True)


def make_live_vehicle_urls_clean(root: Path) -> None:
    script = root / "live-catalog.js"
    if not script.is_file():
        return
    source = script.read_text(encoding="utf-8")
    source = source.replace(
        "const detailsUrl = new URL(`avtomobili/avtomobil/?slug=${encodeURIComponent(car.slug)}`, siteRoot).href;",
        "const detailsUrl = new URL(`avtomobili/${encodeURIComponent(car.slug)}/`, siteRoot).href;",
    )
    source = source.replace("      car.year,\n", "      car.year_detail || car.year,\n")
    script.write_text(source, encoding="utf-8", newline="")


def main() -> int:
    if len(sys.argv) != 2:
        print("Usage: enrich_site_seo.py <unpacked-site-root>", file=sys.stderr)
        return 2
    root = Path(sys.argv[1]).resolve()
    if not (root / "index.html").is_file() or not (root / "avtomobili" / "index.html").is_file():
        print("Site root is missing required MIR AUTO pages.", file=sys.stderr)
        return 1
    for page in sorted(root.rglob("index.html")):
        enrich(page, root)
    update_sitemap(root)
    make_live_vehicle_urls_clean(root)
    robots = root / "robots.txt"
    if not robots.exists() or f"Sitemap: {SITE}/sitemap.xml" not in robots.read_text(encoding="utf-8"):
        print("robots.txt must reference the canonical sitemap URL.", file=sys.stderr)
        return 1
    print("SEO metadata and sitemap generated for the MIR AUTO site.")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
