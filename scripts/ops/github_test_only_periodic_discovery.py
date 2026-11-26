"""Bounded discovery using the certified mailbox parser. No writes or execution."""
import datetime as dt
import time
import importlib.util
from pathlib import Path
HERE=Path(__file__).resolve().parent
def load():
    spec=importlib.util.spec_from_file_location("rx",HERE/"github-test-only-bridge.py")
    rx=importlib.util.module_from_spec(spec);spec.loader.exec_module(rx)
    return rx

def discover(gh,token,approved_sha,now=None):
    rx=load()
    now=time.time() if now is None else now
    root="/repos/"+rx.REPO
    repo=gh(token,"GET",root)
    issue=gh(token,"GET",root+"/issues/3")
    if repo.get("private") is not True or repo.get("full_name")!=rx.REPO or issue.get("state")!="open" or issue.get("title")!="CV contingency — diagnostic mailbox" or "pull_request" in issue:
        raise ValueError("mailbox verification failed")
    since=dt.datetime.fromtimestamp(now-900,dt.timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ")
    comments=gh(token,"GET",root+"/issues/3/comments?per_page=100&since="+since)
    if not isinstance(comments,list) or len(comments)>=100:raise ValueError("bounded mailbox exceeded")
    result=[]
    for c in comments:
        if c.get("issue_url")!="https://api.github.com"+root+"/issues/3":continue
        req=rx.parse_comment(c,now)
        if req and req["sha"]==approved_sha:result.append(req)
    return result
