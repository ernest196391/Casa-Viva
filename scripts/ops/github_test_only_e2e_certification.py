#!/usr/bin/env python3
"""One-shot isolated integration coordinator; no production DB or live worker.
The operator supplies an existing private mailbox token through environment.
Only one explicitly selected owner request is accepted. No polling daemon.
"""
import importlib.util
import json
import os
from pathlib import Path
import re
import subprocess
import sys
import time

HERE=Path(__file__).resolve().parent
def load(name,path):
    spec=importlib.util.spec_from_file_location(name,HERE/path)
    mod=importlib.util.module_from_spec(spec);spec.loader.exec_module(mod);return mod
rx=load("intake","github-test-only-bridge.py")
ex=load("executor","github_test_only_executor.py")
rp=load("reporter","github_test_only_reporter.py")
ghmod=load("transport","github_contingency.py")
cert=load("cert","github_test_only_launcher_certification.py")

def run(api,gh,env,comment_id,sha,launch,now=None):
    if type(comment_id) is not int or comment_id<=0 or not re.fullmatch("[0-9a-f]{40}",sha):
        raise ValueError("invalid fixture identity")
    now=time.time() if now is None else now
    token=env["CV_GITHUB_CONTINGENCY_TOKEN"]
    root="/repos/"+rx.REPO
    path=root+"/issues/3/comments"
    # Scope all writes to the one selected request; never drain the real mailbox.
    def scoped(t,verb,p,body=None):
        if t!=token:raise ValueError("token mismatch")
        if verb=="GET" and p.startswith(path+"?") and "&since=" in p:
            comment=gh(t,"GET",root+"/issues/comments/"+str(comment_id))
            request=rx.parse_comment(comment,now)
            if not request or request["comment_id"]!=comment_id or request["sha"]!=sha or comment.get("issue_url")!="https://api.github.com"+root+"/issues/3":
                raise ValueError("request mismatch")
            return [comment]
        if verb=="POST":
            if p!=path or not isinstance(body,dict) or set(body)!={"body"}:raise ValueError("write outside fixture")
            raw=body["body"]
            if not raw.startswith(rp.PREFIX):raise ValueError("invalid result")
            result=json.loads(raw[len(rp.PREFIX):])
            if result.get("comment_id")!=comment_id or result.get("sha")!=sha:raise ValueError("result mismatch")
        return gh(t,verb,p,body) if body is not None else gh(t,verb,p)
    for _ in range(2):
        if rx.receive_once(env,now,scoped,lambda p,d:api("POST",p,d))!="queued":raise ValueError("intake blocked")
    calls=[]
    outcomes=[]
    def execute(s,b):
        if s!=sha or calls:raise ValueError("unexpected claim")
        calls.append(s)
        rc=launch(s,b)
        outcomes.append(rc)
        return rc
    if not ex.worker_once(api,execute):raise ValueError("missing claim")
    if ex.worker_once(api,execute):raise ValueError("second claim")
    if not rp.deliver(api,scoped,token,rx.OWNER_ID):raise ValueError("missing acknowledgement")
    if rp.deliver(api,scoped,token,rx.OWNER_ID):raise ValueError("duplicate acknowledgement")
    return {"ok":outcomes==[0],"scope":"isolated_e2e","requests":1,"launches":len(calls),"acknowledged":True,"production_authorized":False}
