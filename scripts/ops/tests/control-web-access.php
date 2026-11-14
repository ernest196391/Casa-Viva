<?php
declare(strict_types=1);
// Run the real access guard with WordPress capability stubs; no live credentials/data.
$source = file_get_contents(__DIR__ . '/../control-web/index.php');
$start = strpos($source, '// Control access:');
$end = strpos($source, "$" . "store = dirname", $start);
if ($start === false || $end === false) { throw new RuntimeException('Access guard missing'); }
$guard = substr($source, $start, $end - $start);
function get_current_user_id() { return $GLOBALS['testId']; }
function current_user_can($cap) { return $GLOBALS['testAdmin'] && $cap === 'manage_options'; }
function status_header($status) { throw new RuntimeException('denied', $status); }
function wp_send_json_error($data, $status) { throw new RuntimeException('denied', $status); }
foreach ([
 [1,true,'state',true,true], [1,true,'task',true,true], [1,true,'decision',true,true],
 [14,true,'state',true,false], [14,true,'',true,false],
 [14,true,'task',false,false], [14,true,'decision',false,false],
 [14,false,'state',false,false], [1,false,'state',false,false],
 [15,true,'state',false,false], [0,false,'state',false,false],
] as [$id,$admin,$action,$expectedRead,$expectedWrite]) {
 $GLOBALS['testId']=$id; $GLOBALS['testAdmin']=$admin;
 $accepted=true; $canRead=false; $canWrite=false;
 try { eval($guard); } catch (RuntimeException $e) {
  if ($e->getCode() !== 403) { throw $e; } $accepted=false;
 }
 if ($accepted !== $expectedRead || ($accepted && $canWrite !== $expectedWrite)) {
  throw new RuntimeException("Unexpected access for user $id action $action");
 }
}
echo "Control owner access: 11 boundary cases passed\n";
