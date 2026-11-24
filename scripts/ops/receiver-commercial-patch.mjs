import { createHash } from 'node:crypto';

const base = 'b4c6477da70dff515367038ba6abf6dbe722b323e05227216d160dddb0dca92f';

function replaceOnce(source, before, after) {
  if (source.split(before).length !== 2) throw new Error('Receiver anchor changed');
  return source.replace(before, () => after);
}

export function patchCommercialReceiver(source) {
  if (createHash('sha256').update(source).digest('hex') !== base) {
    throw new Error('Unexpected recovery-replies.mjs version');
  }
  let code = "import { alternateCatalogQuery, cleanCatalogQuery, isCommissionQuestion } from './receiver-commercial-ai.mjs';\n" + source;
  const commission = " if(ownerSelf){";
  code = replaceOnce(code, commission, " if(!k.fromMe&&/^[0-9]{10,15}@s\\.whatsapp\\.net$/.test(jid)&&isCommissionQuestion(text))return {jid,id:k.id,kind:'mycommission',phone:jid.split('@')[0]};\n" + commission);
  const lookup = "u.searchParams.set('search',c.query);";
  code = replaceOnce(code, lookup, "const query=cleanCatalogQuery(c.query);u.searchParams.set('search',query);");
  const products = "const a=await r.json();if(!Array.isArray(a))throw Error('invalid catalog');";
  code = replaceOnce(code, products, "let a=await r.json();if(!Array.isArray(a))throw Error('invalid catalog');\n  if(a.length===0){const alternate=await alternateCatalogQuery(query);if(alternate){u.searchParams.set('search',alternate);const retry=await fetcher(u,{signal:AbortSignal.timeout(5000),headers:{Accept:'application/json'}}).catch(()=>null);if(retry?.ok){const candidates=await retry.json().catch(()=>null);if(Array.isArray(candidates))a=candidates;}}}");
  return code;
}
