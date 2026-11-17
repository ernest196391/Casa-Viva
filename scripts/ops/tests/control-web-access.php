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

// Exercise the production predicate without booting WordPress.
$start = strpos($source, 'function cv_control_approval_linked(');
$end = strpos($source, "if ($" . "action === 'decision')", $start);
if ($start === false || $end === false) { throw new RuntimeException('Approval predicate missing'); }
eval(substr($source, $start, $end - $start));
$now = 1800000000;
$valid = ['at'=>gmdate('c',$now),'approval_complete'=>true,'decisions'=>[['approval_id'=>123,'task_id'=>456]]];
foreach ([
 [$valid,123,true], [$valid,124,false], [$valid,0,false],
 [array_replace($valid,['approval_complete'=>false]),123,false],
 [array_replace($valid,['at'=>gmdate('c',$now-181)]),123,false],
 [array_replace($valid,['at'=>gmdate('c',$now+181)]),123,false],
 [array_replace($valid,['decisions'=>[['approval_id'=>123,'task_id'=>null]]]),123,false],
 [null,123,false],
] as [$snapshot,$approvalId,$expected]) {
 if (cv_control_approval_linked($snapshot,$approvalId,$now) !== $expected) { throw new RuntimeException('Approval boundary failed'); }
}
echo "Approval integrity: 8 boundary cases passed\n";
