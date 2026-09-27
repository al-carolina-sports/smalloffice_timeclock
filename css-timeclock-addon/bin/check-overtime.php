<?php
/**
 * Overtime split checks. Run: php bin/check-overtime.php
 */
define('ABSPATH', '/'); define('HOUR_IN_SECONDS', 3600);
require __DIR__ . '/../includes/class-overtime.php';
$H=3600; $f=0;
$cases = [
 // [weeks hours], threshold h, window, expected OT hours per week
 [[48,32],40,1,[8,0]],
 [[48,32],80,2,[0,0]],
 [[50,40],80,2,[0,10]],
 [[30,50],40,1,[0,10]],
 [[40,40],40,1,[0,0]],
 [[90,0],80,2,[10,0]],
 [[45],44,1,[1]],
 [[0,0],40,1,[0,0]],
 [[48,32],40,2,[8,32]],
];
foreach ($cases as [$w,$t,$n,$exp]) {
  $got = array_map(fn($s)=>$s/$H, Css_Tc_Overtime::split(array_map(fn($h)=>$h*$H,$w), $t*$H, $n));
  $ok = $got == $exp; if(!$ok) $f++;
  printf("%s weeks=%s thr=%d/%dwk -> %s\n", $ok?'PASS':'FAIL', json_encode($w), $t, $n, json_encode($got));
}
foreach ([[2,2,2],[2,1,1],[1,2,1],[5,2,2],[0,2,1]] as [$w,$p,$e]) { $g=Css_Tc_Overtime::clamp_weeks($w,$p); if($g!==$e){$f++;} printf("%s clamp(%d,%d)=%d\n",$g===$e?'PASS':'FAIL',$w,$p,$g); }
exit($f?1:0);
