"""Terminal report delivery using existing ops_tasks and GitHub mailbox.
Single writer under existing worker lock. Reconcile remote before retrying.
Never return raw logs, credentials, or candidate-controlled text.
"""
import json
import re

PREFIX="CV-TEST-ONLY-RESULT-V1 "
OUTCOMES={"passed","failed","timeout","blocked","expired"}
REPO="ernest196391/casa-viva-centro-control"

def report_body(row):
    result=row["result"]
    outcome=result["outcome"]
    cid=row["source_ref"]
    sha=row["payload"]["test_only"]["sha"]
    public_id=row["public_id"]
    if outcome not in OUTCOMES or not isinstance(cid,str) or not re.fullmatch(r"[1-9][0-9]{0,19}",cid):
        raise ValueError("invalid terminal row")
    if not isinstance(sha,str) or not re.fullmatch(r"[0-9a-f]{40}",sha) or type(public_id) is not int or public_id<1:
        raise ValueError("invalid terminal identity")
    return PREFIX+json.dumps({"comment_id":int(cid),"task_public_id":public_id,
        "sha":sha,"outcome":outcome},sort_keys=True,separators=(",",":"))

def deliver(api,gh,token,author_id):
    if type(author_id) is not int or author_id<=0: raise ValueError("reporter identity")
    root="/repos/"+REPO
    repo=gh(token,"GET",root)
    issue=gh(token,"GET",root+"/issues/3")
    if repo.get("private") is not True or repo.get("full_name")!=REPO or issue.get("state")!="open" or issue.get("title")!="CV contingency — diagnostic mailbox" or "pull_request" in issue:
        return False
    rows=api("GET","ops_tasks?source=eq.github-test-only&status=in.(done,failed)&result->>github_report_id=is.null&select=id,public_id,source_ref,payload,result&order=public_id.asc&limit=1")
    if not isinstance(rows,list): raise ValueError("invalid queue response")
    if not rows:return False
    row=rows[0]; body=report_body(row); remote_id=None
    path=root+"/issues/3/comments"
    # Full bounded reconciliation, not a time window: an interrupted POST
    # may have succeeded days earlier. Fail closed if backlog exceeds cap.
    for page in range(1,21):
        comments=gh(token,"GET",path+"?per_page=100&page="+str(page))
        if not isinstance(comments,list):raise ValueError("invalid mailbox")
        for c in comments:
            if c.get("body")==body and c.get("user",{}).get("id")==author_id and c.get("created_at")==c.get("updated_at"):
                remote_id=c.get("id");break
        if remote_id is not None or len(comments)<100:break
    else:return False
    if remote_id is None:
        posted=gh(token,"POST",path,{"body":body})
        if posted.get("body")!=body or posted.get("user",{}).get("id")!=author_id:
            raise ValueError("unconfirmed remote receipt")
        remote_id=posted.get("id")
    if type(remote_id) is not int or remote_id<=0:raise ValueError("invalid receipt")
    ack=api("POST","rpc/ops_ack_github_test_only",{"p_task_id":row["id"],"p_comment_id":remote_id})
    if ack is not True:raise ValueError("unconfirmed durable acknowledgement")
    return True
