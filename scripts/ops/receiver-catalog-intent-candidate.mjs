import { createHash } from 'node:crypto';

const base = 'df60609d5a94d5070af7ab77b399e9a191d779f27e7dc2b5237b2d30415077f8';
const anchor = ' const q=t.replace(';

export function patchCatalogIntent(source) {
  if (createHash('sha256').update(source).digest('hex') !== base) {
    throw new Error('Unexpected natural-dialog.mjs version');
  }
  if (source.indexOf(anchor) === -1 || source.indexOf(anchor) !== source.lastIndexOf(anchor)) {
    throw new Error('Catalog matcher anchor changed');
  }
  const normalization = String.raw` t=t.replace(/^(?:(?:hola|buenas|buenos días|buenas tardes|buenas noches)[,\s]+)?[¿¡\s]*qu[eé]\s+(.{2,70}?)\s+tienen\s+(?:disponibles?|en stock)[?!. \t]*$/i, (_,product)=>{
  const query=product.trim().replace(/^ventiladores recargables$/i,'ventilador recargable');
  return 'tienen '+query;
 });
`;
  return source.replace(anchor, () => normalization + anchor);
}
