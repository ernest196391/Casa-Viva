import fs from 'node:fs';

const hook = fs.readFileSync('wordpress/casa-viva-dropship-core/includes/class-cvd-messenger-simplification.php', 'utf8');
const guard = fs.readFileSync('wordpress/casa-viva-dropship-core/assets/messenger-feed-stability.js', 'utf8');

function expect(condition, message) {
  if (!condition) throw new Error(message);
}

expect(hook.includes("add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ), 1 )"), 'stability guard must enqueue before portal assets');
expect(hook.includes("'cvd-messenger-feed-stability'"), 'stability script must be enqueued');
expect(guard.includes("'/wp-json/casa-viva/v1/messenger/feed'"), 'guard must target only messenger feed');
expect(guard.includes("document.querySelectorAll('[data-delivery-id]')"), 'guard must snapshot visible delivery cards');
expect(guard.includes('data.deliveries.filter'), 'guard must remove server-only deliveries from comparison');
expect(guard.includes('if (!exists) aligned.push(snapshot)'), 'guard must synthesize missing visible deliveries to avoid false reloads');

console.log('Messenger immediate reload guard contract OK');
