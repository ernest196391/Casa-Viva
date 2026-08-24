import fs from 'node:fs';

const plugin = fs.readFileSync('wordpress/casa-viva-dropship-core/casa-viva-dropship-core.php', 'utf8');
const guard = fs.readFileSync('wordpress/casa-viva-dropship-core/includes/class-cvd-messenger-feed-guard.php', 'utf8');

function expect(condition, message) {
  if (!condition) throw new Error(message);
}

expect(plugin.includes("class-cvd-messenger-feed-guard.php"), 'plugin must require messenger feed guard');
expect(plugin.includes('CVD_Messenger_Feed_Guard::register();'), 'plugin must register messenger feed guard');
expect(guard.includes("'/casa-viva/v1/messenger/feed'"), 'guard must target only messenger feed');
for (const status of ['delivered', 'cash_returned', 'closed']) {
  expect(guard.includes(`'${status}'`), `guard must exclude ${status} from polling feed`);
}
expect(guard.includes("add_filter( 'rest_post_dispatch'"), 'guard must filter final REST response');

console.log('Messenger reload-loop guard contract OK');
