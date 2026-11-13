#!/usr/bin/env python3
"""Read-only image QA for Aserezon source photos. This does not alter WordPress."""
import io
import json
import urllib.request
from pathlib import Path

from PIL import Image

SOURCE=Path("data/catalog/aserezon-source-media-candidates.json")
OUTPUT=Path("aserezon-photo-quality.json")
def test(item):
    result={"sku":item["sku"],"source_url":item["url"],"status":"not_reviewed"}
    try:
        req=urllib.request.Request(item["url"],headers={"User-Agent":"Mozilla/5.0 CasaVivaCatalogReview/1.0"})
        with urllib.request.urlopen(req,timeout=25) as response:
            if response.status != 200:
                raise ValueError("bad_status")
            data=response.read(9_000_001)
        if len(data)>9_000_000:
            raise ValueError("too_large")
        with Image.open(io.BytesIO(data)) as img:
            img.load()
            result["format"]=img.format
            result["width"],result["height"]=img.size
            original=img.convert("RGBA")
            bg=Image.new("RGBA",original.size,(255,255,255,255))
            rgb=Image.alpha_composite(bg,original).convert("RGB")
            width,height=rgb.size
            sample=[]
            for xbase in [0.02,0.98]:
                for ybase in [0.02,0.98]:
                    for ox in [-0.01,0,0.01]:
                        for oy in [-0.01,0,0.01]:
                            x=min(width-1,max(0,round(width*(xbase+ox))))
                            y=min(height-1,max(0,round(height*(ybase+oy))))
                            sample.append(rgb.getpixel((x,y)))
            white=sum(1 for r,g,b in sample if min(r,g,b)>=235)
            result["white_corner_fraction"]=round(white/len(sample),3)
            # This is automated screening, not final human visual QA.
            result["screening"]="candidate_white" if min(width,height)>=500 and white/len(sample)>=0.8 else "manual_review"
            result["status"]="ok"
    except Exception as e:
        result["status"]="error"
        result["error"]=type(e).__name__
    return result
def main():
    manifest=json.loads(SOURCE.read_text(encoding="utf8"))
    items=[test(x) for x in manifest["photos"]]
    OUTPUT.write_text(json.dumps({"status":"screening_only","photos":items},ensure_ascii=False,indent=2)+"\n",encoding="utf8")
    print(json.dumps({"ok":sum(x["status"]=="ok" for x in items),"white_candidates":sum(x.get("screening")=="candidate_white" for x in items),"errors":sum(x["status"]=="error" for x in items)}))
if __name__=="__main__":
    main()
