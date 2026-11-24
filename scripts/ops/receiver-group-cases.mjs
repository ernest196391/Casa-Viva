import assert from 'node:assert/strict';

const group = '1000000000-1000000000@g.us';
const courier = '10000000001@s.whatsapp.net';
const now = 1700000000000;

// groupCommand must be loaded by the existing isolated harness.
// This suite supplies every dependency that could touch WhatsApp or Core.
export async function probeGroupClassification(groupCommand, subject) {
  const socket = {
    async groupMetadata() {
      return { id: group, subject, participants: [{ id: courier }] };
    },
  };
  const cases = [
    { name: 'unique_offer', text: 'Yo', offer: 900001, expected: ['valeclaim', 900001] },
    { name: 'explicit_order', text: 'YO #900002', expected: ['valeclaim', 900002] },
    { name: 'quoted_offer', text: 'Yo', quote: 'SYNTHETIC-QUOTE', expected: ['valeclaim', 900003] },
    { name: 'unresolved_offer', text: 'Yo', expected: ['groupclaimconfirm', null] },
    { name: 'release', text: 'Suelto #900001', expected: ['valerelease', 900001] },
    { name: 'other_group', text: 'Suelto #900001', allowed: false, expected: null },
  ];
  for (const fixture of cases) {
    const message = {
      key: { remoteJid: group, participant: courier, id: 'synthetic-' + fixture.name },
      messageTimestamp: now / 1000,
      message: fixture.quote
        ? { extendedTextMessage: { text: fixture.text, contextInfo: { stanzaId: fixture.quote } } }
        : { conversation: fixture.text },
    };
    const result = await groupCommand(message, socket, {
      now,
      identify: async (jid) => jid === group && fixture.allowed !== false,
      resolveQuote: async (jid, id) => jid === group && id === fixture.quote ? 900003 : null,
      singleOffer: async () => fixture.offer ?? null,
    });
    assert.deepEqual(result ? [result.kind, result.order] : null, fixture.expected, fixture.name);
  }
  return cases.map(({ name }) => name);
}
