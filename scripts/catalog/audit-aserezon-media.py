#!/usr/bin/env python3
"""Read-only retrieval of product gallery candidates from the source site.
No image files are published, no WooCommerce write, and all sources remain attributed.
"""
import html
import json
import re
import urllib.parse
import urllib.request
from html.parser import HTMLParser
from pathlib import Path

SOURCE = Path("data/catalog/aserezon-piloto-borrador.json")
OUTPUT = Path("aserezon-source-images.json")

class GalleryParser(HTMLParser):
    def __init__(self):
        super().__init__()
        self.candidates = []
        self.page_title = None
        self.in_title = False
    def handle_starttag(self, tag, attrs):
        d = dict(attrs)
        if tag == "title":
            self.in_title = True
        if tag == "meta":
            key = d.get("property") or d.get("name") or ""
            if key in {"og:image", "twitter:image"}:
                self.candidates.append({"origin":key,"url":d.get("content","")})
        if tag == "img":
            for key in ("data-large_image", "data-src", "src", "srcset"):
                raw = d.get(key,"")
                if raw:
                    if key == "srcset":
                        raw = raw.split(",")[-1].strip().split(" ")[0]
                    self.candidates.append({"origin":"img:"+key,"url":raw,"alt":d.get("alt","")})
        if tag == "a" and d.get("href") and "woocommerce-product-gallery__image" in d.get("class",""):
            self.candidates.append({"origin":"gallery:href","url":d["href"]})
    def handle_endtag(self, tag):
        if tag == "title":
            self.in_title = False
    def handle_data(self, value):
        if self.in_title:
            self.page_title = (self.page_title or "") + value

def main():
    catalog = json.loads(SOURCE.read_text(encoding="utf-8"))
    report = {"status":"source_review_only","items":[]}
    for item in catalog["items"]:
        url = item["source"]
        parsed = urllib.parse.urlparse(url)
        row = {"sku":item["sku"],"source":url,"manufacturer_code":item["manufacturer_code"],"candidates":[]}
        if parsed.scheme != "https" or parsed.hostname not in {"aserezon.com", "www.aserezon.com"}:
            row["error"] = "untrusted_source_domain"
            report["items"].append(row)
            continue
        try:
            req = urllib.request.Request(url,headers={"User-Agent":"Mozilla/5.0 CasaVivaCatalogReview/1.0"})
            with urllib.request.urlopen(req,timeout=20) as response:
                if response.status != 200:
                    raise ValueError("http_" + str(response.status))
                data = response.read(3_000_000).decode("utf-8","replace")
            parser = GalleryParser()
            parser.feed(data)
            row["source_page_title"] = html.unescape(parser.page_title or "")
            seen=set()
            for c in parser.candidates:
                full = urllib.parse.urljoin(url, html.unescape(c["url"]))
                p = urllib.parse.urlparse(full)
                if p.scheme != "https" or p.hostname not in {"aserezon.com","www.aserezon.com"}:
                    continue
                if not re.search(r"\.(jpg|jpeg|png|webp)(?:$|\?)",p.path,re.I):
                    continue
                clean = urllib.parse.urlunparse(p._replace(fragment=""))
                if clean in seen:
                    continue
                seen.add(clean)
                row["candidates"].append({"url":clean,"origin":c["origin"],"alt":c.get("alt","")})
            row["candidates"] = row["candidates"][:15]
        except Exception as e:
            row["error"] = type(e).__name__
        report["items"].append(row)
    OUTPUT.write_text(json.dumps(report,ensure_ascii=False,indent=2)+"\n",encoding="utf-8")
    print(json.dumps({"items":len(report["items"]),"candidates":sum(len(i["candidates"]) for i in report["items"])}))

if __name__ == "__main__":
    main()
