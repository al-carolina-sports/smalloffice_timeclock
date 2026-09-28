<?php
/**
 * Overtime split checks. Run: php bin/check-overtime.php
 */

if ( PHP_SAPI !== 'cli' ) {
	header( 'HTTP/1.1 403 Forbidden' );
	exit;
}
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
// allocate(): segments in time order, [week, hours, company]
$alloc_cases = [
 // CSS 8h x5 = 40 then BFM 5h on Friday afternoon -> OT 5h charged to BFM (combined)
 ['combined 40/1: BFM crosses the line', [[0,8,'css'],[0,8,'css'],[0,8,'css'],[0,8,'css'],[0,4,'css'],[0,4,'bfm'],[0,5,'bfm']], 40, 1, false, [0,0,0,0,0,0,5]],
 // same hours, per company: no one company passes 40
 ['per company 40/1: no OT', [[0,8,'css'],[0,8,'css'],[0,8,'css'],[0,8,'css'],[0,4,'css'],[0,4,'bfm'],[0,5,'bfm']], 40, 1, true, [0,0,0,0,0,0,0]],
 // segment straddles the threshold: 38 then a 4h segment -> 2 OT
 ['straddle', [[0,38,'a'],[0,4,'b']], 40, 1, false, [0,2]],
 // two weeks, weekly windows reset
 ['weekly reset', [[0,45,'a'],[1,45,'a']], 40, 1, false, [5,5]],
 // two-week window: 45 + 45 = 90 over 80 -> 10 in week 2
 ['biweekly window', [[0,45,'a'],[1,45,'a']], 80, 2, false, [0,10]],
];
foreach ($alloc_cases as [$name,$segs,$t,$n,$per,$exp]) {
  $in=[]; foreach ($segs as $i=>[$w,$h,$g]) $in[$i]=['week'=>$w,'seconds'=>$h*$H,'group'=>$g];
  $got = array_values(array_map(fn($s)=>$s/$H, Css_Tc_Overtime::allocate($in,$t*$H,$n,$per)));
  $ok = $got == $exp; if(!$ok) $f++;
  printf("%s allocate %s -> %s\n", $ok?'PASS':'FAIL', $name, json_encode($got));
}
exit($f?1:0);
