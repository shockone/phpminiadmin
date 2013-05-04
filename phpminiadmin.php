<?php
/*
 PHP Mini MySQL Admin
 (c) 2004-2012 Oleg Savchuk <osalabs@gmail.com> http://osalabs.com

 Light standalone PHP script for quick and easy access MySQL databases.
 http://phpminiadmin.sourceforge.net

 Dual licensed: GPL v2 and MIT, see texts at http://opensource.org/licenses/
*/

// Only one day lifetime.
if (getlastmod() < strtotime('A day ago')) {
  echo 'Touch the file.';
  return;
}

 $ACCESS_PWD=''; #!!!IMPORTANT!!! this is script access password, SET IT if you want to protect you DB from public access


// Get the DB username, password and DB name from the shopping cart config.
$username = '';
$password = '';
$db_name = '';
$hostname = '';

if (file_exists('includes/configure.php')) { // osCommerce and company
  require_once 'includes/configure.php';
  $username = DB_SERVER_USERNAME;
  $password = DB_SERVER_PASSWORD;
  $db_name = DB_DATABASE;
  $hostname = DB_SERVER;
} elseif (file_exists('config.php')){    // X-Cart
  define('XCART_START', 'placeholder');
  require_once 'config.php';
  $username = $sql_user;
  $password = $sql_password;
  $db_name = $sql_db;
  $hostname = $sql_host;
}


 #DEFAULT db connection settings
 # --- WARNING! --- if you set defaults - it's recommended to set $ACCESS_PWD to protect your db!
 $DBDEF=array(
 'user'=>$username,#required
 'pwd'=>$password, #required
 'db'=>$db_name,  #optional, default DB
 'host'=>$hostname,#optional
 'port'=>"",#optional
 'chset'=>"utf8",#optional, default charset
 );
 date_default_timezone_set('UTC');#required by PHP 5.1+

//constants
 $VERSION='1.8.120514';
 $MAX_ROWS_PER_PAGE=50; #max number of rows in select per one page
 $D="\r\n"; #default delimiter for export
 $BOM=chr(239).chr(187).chr(191);
 $SHOW_T="SHOW TABLE STATUS";
 $DB=array(); #working copy for DB settings

 $self=$_SERVER['PHP_SELF'];

 session_start();
 if (!isset($_SESSION['XSS'])) $_SESSION['XSS']=get_rand_str(16);
 $xurl='XSS='.$_SESSION['XSS'];

 ini_set('display_errors',1);  #TODO turn off before deploy
 error_reporting(E_ALL ^ E_NOTICE);

//strip quotes if they set
 if (get_magic_quotes_gpc()){
  $_COOKIE=array_map('killmq',$_COOKIE);
  $_REQUEST=array_map('killmq',$_REQUEST);
 }

 if (!$ACCESS_PWD) {
    $_SESSION['is_logged']=true;
    loadcfg();
 }

 if ($_REQUEST['login']){
    if ($_REQUEST['pwd']!=$ACCESS_PWD){
       $err_msg="Invalid password. Try again";
    }else{
       $_SESSION['is_logged']=true;
       loadcfg();
    }
 }

 if ($_REQUEST['logoff']){
    check_xss();
    $_SESSION = array();
    savecfg();
    session_destroy();
    $url=$self;
    if (!$ACCESS_PWD) $url='/';
    header("location: $url");
    exit;
 }

 if (!$_SESSION['is_logged']){
    print_login();
    exit;
 }

 if ($_REQUEST['savecfg']){
    check_xss();
    savecfg();
 }

 loadsess();

 if ($_REQUEST['showcfg']){
    print_cfg();
    exit;
 }

 //get initial values
 $SQLq=trim($_REQUEST['q']);
 $page=$_REQUEST['p']+0;
 if ($_REQUEST['refresh'] && $DB['db'] && preg_match('/^show/',$SQLq) ) $SQLq=$SHOW_T;

 if (db_connect('nodie')){
    $time_start=microtime_float();

    if ($_REQUEST['phpinfo']){
       ob_start();phpinfo();$sqldr='<div style="font-size:130%">'.ob_get_clean().'</div>';
    }else{
     if ($DB['db']){
      if ($_REQUEST['shex']){
       print_export();
      }elseif ($_REQUEST['doex']){
       check_xss();do_export();
      }elseif ($_REQUEST['shim']){
       print_import();
      }elseif ($_REQUEST['doim']){
       check_xss();do_import();
      }elseif ($_REQUEST['dosht']){
       check_xss();do_sht();
      }elseif (!$_REQUEST['refresh'] || preg_match('/^select|show|explain|desc/i',$SQLq) ){
       if ($SQLq)check_xss();
       do_sql($SQLq);#perform non-select SQL only if not refresh (to avoid dangerous delete/drop)
      }
     }else{
        if ( $_REQUEST['refresh'] ){
           check_xss();do_sql('show databases');
        }elseif ( preg_match('/^show\s+(?:databases|status|variables|process)/i',$SQLq) ){
           check_xss();do_sql($SQLq);
        }else{
           $err_msg="Select Database first";
           if (!$SQLq) do_sql("show databases");
        }
     }
    }
    $time_all=ceil((microtime_float()-$time_start)*10000)/10000;

    print_screen();
 }else{
    print_cfg();
 }

function do_sql($q){
 global $dbh,$last_sth,$last_sql,$reccount,$out_message,$SQLq,$SHOW_T;
 $SQLq=$q;

 if (!do_multi_sql($q)){
    $out_message="Error: ".mysql_error($dbh);
 }else{
    if ($last_sth && $last_sql){
       $SQLq=$last_sql;
       if (preg_match("/^select|show|explain|desc/i",$last_sql)) {
          if ($q!=$last_sql) $out_message="Results of the last select displayed:";
          display_select($last_sth,$last_sql);
       } else {
         $reccount=mysql_affected_rows($dbh);
         $out_message="Done.";
         if (preg_match("/^insert|replace/i",$last_sql)) $out_message.=" Last inserted id=".get_identity();
         if (preg_match("/^drop|truncate/i",$last_sql)) do_sql($SHOW_T);
       }
    }
 }
}

function display_select($sth,$q){
 global $dbh,$DB,$sqldr,$reccount,$is_sht,$xurl;
 $rc=array("o","e");
 $dbn=$DB['db'];
 $sqldr='';

 $is_shd=(preg_match('/^show\s+databases/i',$q));
 $is_sht=(preg_match('/^show\s+tables|^SHOW\s+TABLE\s+STATUS/',$q));
 $is_show_crt=(preg_match('/^show\s+create\s+table/i',$q));

 if ($sth===FALSE or $sth===TRUE) return;#check if $sth is not a mysql resource

 $reccount=mysql_num_rows($sth);
 $fields_num=mysql_num_fields($sth);

 $w='';
 if ($is_sht || $is_shd) {$w='wa';
   $url='?'.$xurl."&db=$dbn";
   $sqldr.="<div class='dot'>
&nbsp;MySQL Server:
&nbsp;&#183;<a href='$url&q=show+variables'>Show Configuration Variables</a>
&nbsp;&#183;<a href='$url&q=show+status'>Show Statistics</a>
&nbsp;&#183;<a href='$url&q=show+processlist'>Show Processlist</a>
<br>";
   if ($is_sht) $sqldr.="&nbsp;Database:&nbsp;&#183;<a href='$url&q=show+table+status'>Show Table Status</a>";
   $sqldr.="</div>";
 }
 if ($is_sht){
   $abtn="&nbsp;<input type='submit' value='Export' onclick=\"sht('exp')\">
 <input type='submit' value='Drop' onclick=\"if(ays()){sht('drop')}else{return false}\">
 <input type='submit' value='Truncate' onclick=\"if(ays()){sht('trunc')}else{return false}\">
 <input type='submit' value='Optimize' onclick=\"sht('opt')\">
 <b>selected tables</b>";
   $sqldr.=$abtn."<input type='hidden' name='dosht' value=''>";
 }

 $sqldr.="<table class='res $w'>";
 $headers="<tr class='h'>";
 if ($is_sht) $headers.="<td><input type='checkbox' name='cball' value='' onclick='chkall(this)'></td>";
 for($i=0;$i<$fields_num;$i++){
    if ($is_sht && $i>0) break;
    $meta=mysql_fetch_field($sth,$i);
    $headers.="<th>".$meta->name."</th>";
 }
 if ($is_shd) $headers.="<th>show create database</th><th>show table status</th><th>show triggers</th>";
 if ($is_sht) $headers.="<th>engine</th><th>~rows</th><th>data size</th><th>index size</th><th>show create table</th><th>explain</th><th>indexes</th><th>export</th><th>drop</th><th>truncate</th><th>optimize</th><th>repair</th>";
 $headers.="</tr>\n";
 $sqldr.=$headers;
 $swapper=false;
 while($row=mysql_fetch_row($sth)){
   $sqldr.="<tr class='".$rc[$swp=!$swp]."' onmouseover='tmv(this)' onmouseout='tmo(this)' onclick='tc(this)'>";
   for($i=0;$i<$fields_num;$i++){
      $v=$row[$i];$more='';
      if ($is_sht && $v){
         if ($i>0) break;
         $vq='`'.$v.'`';
         $url='?'.$xurl."&db=$dbn";
         $v="<input type='checkbox' name='cb[]' value=\"$vq\"></td>"
         ."<td><a href=\"$url&q=select+*+from+$vq\">$v</a></td>"
         ."<td>".$row[1]."</td>"
         ."<td align='right'>".$row[4]."</td>"
         ."<td align='right'>".$row[6]."</td>"
         ."<td align='right'>".$row[8]."</td>"
         ."<td>&#183;<a href=\"$url&q=show+create+table+$vq\">sct</a></td>"
         ."<td>&#183;<a href=\"$url&q=explain+$vq\">exp</a></td>"
         ."<td>&#183;<a href=\"$url&q=show+index+from+$vq\">ind</a></td>"
         ."<td>&#183;<a href=\"$url&shex=1&t=$vq\">export</a></td>"
         ."<td>&#183;<a href=\"$url&q=drop+table+$vq\" onclick='return ays()'>dr</a></td>"
         ."<td>&#183;<a href=\"$url&q=truncate+table+$vq\" onclick='return ays()'>tr</a></td>"
         ."<td>&#183;<a href=\"$url&q=optimize+table+$vq\" onclick='return ays()'>opt</a></td>"
         ."<td>&#183;<a href=\"$url&q=repair+table+$vq\" onclick='return ays()'>rpr</a>";
      }elseif ($is_shd && $i==0 && $v){
         $url='?'.$xurl."&db=$v";
         $v="<a href=\"$url&q=SHOW+TABLE+STATUS\">$v</a></td>"
         ."<td><a href=\"$url&q=show+create+database+`$v`\">sct</a></td>"
         ."<td><a href=\"$url&q=show+table+status\">status</a></td>"
         ."<td><a href=\"$url&q=show+triggers\">trig</a></td>"
         ;
      }else{
       if (is_null($v)) $v="NULL";
       $v=htmlspecialchars($v);
      }
      if ($is_show_crt) $v="<pre>$v</pre>";
      $sqldr.="<td>$v".(!strlen($v)?"<br>":'')."</td>";
   }
   $sqldr.="</tr>\n";
 }
 $sqldr.="</table>\n".$abtn;

}

function print_header(){
 global $err_msg,$VERSION,$DB,$dbh,$self,$is_sht,$xurl,$SHOW_T;
 $dbn=$DB['db'];
?>
<!DOCTYPE html>
<html>
<head><title>phpMiniAdmin</title>
<meta charset="utf-8">
<style type="text/css">
body{font-family:Arial,sans-serif;font-size:80%;padding:0;margin:0}
th,td{padding:0;margin:0}
div{padding:3px}
pre{font-size:125%}
.nav{text-align:center}
.ft{text-align:right;margin-top:20px;font-size:smaller}
.inv{background-color:#069;color:#FFF}
.inv a{color:#FFF}
table.res{width:100%;border-collapse:collapse;}
table.wa{width:auto}
table.res th,table.res td{padding:2px;border:1px solid #fff}
table.restr{vertical-align:top}
tr.e{background-color:#CCC}
tr.o{background-color:#EEE}
tr.h{background-color:#99C}
tr.s{background-color:#FF9}
.err{color:#F33;font-weight:bold;text-align:center}
.frm{width:400px;border:1px solid #999;background-color:#eee;text-align:left}
.frm label.l{width:100px;float:left}
.dot{border-bottom:1px dotted #000}
.ajax{text-decoration: none;border-bottom: 1px dashed;}
.qnav{width:30px}

/*
WICK: Web Input Completion Kit
http://wick.sourceforge.net/
Copyright (c) 2004, Christopher T. Holland,
All rights reserved.

Redistribution and use in source and binary forms, with or without modification, are permitted provided that the following conditions are met:

Redistributions of source code must retain the above copyright notice, this list of conditions and the following disclaimer.
Redistributions in binary form must reproduce the above copyright notice, this list of conditions and the following disclaimer in the documentation and/or other materials provided with the distribution.
Neither the name of the Christopher T. Holland, nor the names of its contributors may be used to endorse or promote products derived from this software without specific prior written permission.
THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS "AS IS" AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT OWNER OR CONTRIBUTORS BE LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES; LOSS OF USE, DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE OF THIS SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.

*/

.floater {
position:absolute;
z-index:2;
bottom:0;
left:0;
display:none;
padding:0;
}

.floater td {
font-family: Gill, Helvetica, sans-serif;
background-color:white;
border:1px inset #979797;
color:black;
}

.matchedSmartInputItem {
font-size:0.8em;
padding: 5px 10px 1px 5px;
margin:0;
cursor:pointer;
}

.selectedSmartInputItem {
color:white;
background-color:#3875D7;
}

#smartInputResults {
padding:0;margin:0;
}

.siwCredit {
margin:0;padding:0;margin-top:10px;font-size:0.7em;color:black;
}
</style>

<script type="text/javascript">
var LSK='pma_',LSKX=LSK+'max',LSKM=LSK+'min',qcur=0,LSMAX=32;

function $(i){return document.getElementById(i)}
function frefresh(){
 var F=document.DF;
 F.method='get';
 F.refresh.value="1";
 F.submit();
}
function go(p,sql){
 var F=document.DF;
 F.p.value=p;
 if(sql)F.q.value=sql;
 F.submit();
}
function ays(){
 return confirm('Are you sure to continue?');
}
function chksql(){
 var F=document.DF,v=F.q.value;
 if(/^\s*(?:delete|drop|truncate|alter)/.test(v)) if(!ays())return false;
 if(lschk(1)){
  var lsm=lsmax()+1,ls=localStorage;
  ls[LSK+lsm]=v;
  ls[LSKX]=lsm;
  //keep just last LSMAX queries in log
  if(!ls[LSKM])ls[LSKM]=1;
  var lsmin=parseInt(ls[LSKM]);
  if((lsm-lsmin+1)>LSMAX){
   lsclean(lsmin,lsm-LSMAX);
  }
 }
 return true;
}
function tmv(tr){
 tr.sc=tr.className;
 tr.className='h';
}
function tmo(tr){
 tr.className=tr.sc;
}
function tc(tr){
 tr.className='s';
 tr.sc='s';
}
function lschk(skip){
 if (!localStorage || !skip && !localStorage[LSKX]) return false;
 return true;
}
function lsmax(){
 var ls=localStorage;
 if(!lschk() || !ls[LSKX])return 0;
 return parseInt(ls[LSKX]);
}
function lsclean(from,to){
 ls=localStorage;
 for(var i=from;i<=to;i++){
  delete ls[LSK+i];ls[LSKM]=i+1;
 }
}
function q_prev(){
 var ls=localStorage;
 if(!lschk())return;
 qcur--;
 var x=parseInt(ls[LSKM]);
 if(qcur<x)qcur=x;
 $('q').value=ls[LSK+qcur];
}
function q_next(){
 var ls=localStorage;
 if(!lschk())return;
 qcur++;
 var x=parseInt(ls[LSKX]);
 if(qcur>x)qcur=x;
 $('q').value=ls[LSK+qcur];
}
function after_load(){
 qcur=lsmax();
}
function logoff(){
 if(lschk()){
  var ls=localStorage;
  var from=parseInt(ls[LSKM]),to=parseInt(ls[LSKX]);
  for(var i=from;i<=to;i++){
   delete ls[LSK+i];
  }
  delete ls[LSKM];delete ls[LSKX];
 }
}
function cfg_toggle(){
 var e=$('cfg-adv');
 e.style.display=e.style.display=='none'?'':'none';
}

<?php
  $sth=db_query("show tables from `$DB[db]`");
  $tables = array();
  while($row=mysql_fetch_row($sth)){
    $tables[] = $row[0];
  }
  echo 'collection = '.json_encode($tables).";\n";
  unset($tables);
?>

collection = collection.concat(['SELECT', 'UPDATE', 'INSERT', 'FROM', 'WHERE', 'GROUP BY', 'ORDER BY', 'INNER JOIN']);

/*
WICK: Web Input Completion Kit
http://wick.sourceforge.net/
Copyright (c) 2004, Christopher T. Holland
All rights reserved.

Redistribution and use in source and binary forms, with or without modification, are permitted provided that the following conditions are met:

Redistributions of source code must retain the above copyright notice, this list of conditions and the following disclaimer.
Redistributions in binary form must reproduce the above copyright notice, this list of conditions and the following disclaimer in the documentation and/or other materials provided with the distribution.
Neither the name of the Christopher T. Holland, nor the names of its contributors may be used to endorse or promote products derived from this software without specific prior written permission.
THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS "AS IS" AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT OWNER OR CONTRIBUTORS BE LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES; LOSS OF USE, DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE OF THIS SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.

*/
/* start dhtml building blocks */
function freezeEvent(e) {
if (e.preventDefault) e.preventDefault();
e.returnValue = false;
e.cancelBubble = true;
if (e.stopPropagation) e.stopPropagation();
return false;
}//freezeEvent

function isWithinNode(e,i,c,t,obj) {
answer = false;
te = e;
while(te && !answer) {
  if  ((te.id && (te.id == i)) || (te.className && (te.className == i+"Class"))
      || (!t && c && te.className && (te.className == c))
      || (!t && c && te.className && (te.className.indexOf(c) != -1))
      || (t && te.tagName && (te.tagName.toLowerCase() == t))
      || (obj && (te == obj))
    ) {
    answer = te;
  } else {
    te = te.parentNode;
  }
}
return te;
}//isWithinNode

function getEvent(event) {
return (event ? event : window.event);
}//getEvent()

function getEventElement(e) {
return (e.srcElement ? e.srcElement: (e.target ? e.target : e.currentTarget));
}//getEventElement()

function findElementPosX(obj) {
  curleft = 0;
  if (obj.offsetParent) {
    while (obj.offsetParent) {
      curleft += obj.offsetLeft;
      obj = obj.offsetParent;
    }
  }//if offsetParent exists
  else if (obj.x)
    curleft += obj.x
  return curleft;
}//findElementPosX

function findElementPosY(obj) {
  curtop = 0;
  if (obj.offsetParent) {
    while (obj.offsetParent) {
      curtop += obj.offsetTop;
      obj = obj.offsetParent;
    }
  }//if offsetParent exists
  else if (obj.y)
    curtop += obj.y
  return curtop;
}//findElementPosY

/* end dhtml building blocks */

function handleKeyPress(event) {
e = getEvent(event);
eL = getEventElement(e);

upEl = isWithinNode(eL,null,"wickEnabled",null,null);

kc = e["keyCode"];

if (siw && ((kc == 13) || (kc == 9))) {
  siw.selectingSomething = true;
  if (siw.isSafari) siw.inputBox.blur();   //hack to "wake up" safari
  siw.inputBox.focus();
  siw.inputBox.value = siw.inputBox.value.replace(/[ \r\n\t\f\s]+$/gi,' ');
  hideSmartInputFloater();
} else if (upEl && (kc != 38) && (kc != 40) && (kc != 37) && (kc != 39) && (kc != 13) && (kc != 27)) {
  if (!siw || (siw && !siw.selectingSomething)) {
    processSmartInput(upEl);
  }
} else if (siw && siw.inputBox) {
  siw.inputBox.focus(); //kinda part of the hack.
}

}//handleKeyPress()


function handleKeyDown(event) {
e = getEvent(event);
eL = getEventElement(e);
if (siw && (kc = e["keyCode"])) {
  if ((kc == 40) || (e.ctrlKey && (kc == 74))) { //Vim like navigation
    siw.selectingSomething = true;
    freezeEvent(e);
    if (siw.isGecko) siw.inputBox.blur(); /* Gecko hack */
    selectNextSmartInputMatchItem();
  } else if (kc == 38 || (e.ctrlKey && (kc == 75))) {
    siw.selectingSomething = true;
    freezeEvent(e);
    if (siw.isGecko) siw.inputBox.blur();
    selectPreviousSmartInputMatchItem();
  } else if ((kc == 13) || (kc == 9)) {
    siw.selectingSomething = true;
    activateCurrentSmartInputMatch();
    freezeEvent(e);
  } else if (kc == 27)  {
    hideSmartInputFloater();
    freezeEvent(e);
  } else {
    siw.selectingSomething = false;
  }
}

}//handleKeyDown()

function handleFocus(event) {
  e = getEvent(event);
  eL = getEventElement(e);
  if (focEl = isWithinNode(eL,null,"wickEnabled",null,null)) {
  if (!siw || (siw && !siw.selectingSomething)) processSmartInput(focEl);
  }
}//handleFocus()

function handleBlur(event) {
  e = getEvent(event);
  eL = getEventElement(e);
  if (blurEl = isWithinNode(eL,null,"wickEnabled",null,null)) {
    if (siw && !siw.selectingSomething) hideSmartInputFloater();
  }
}//handleBlur()

function handleClick(event) {
  e2 = getEvent(event);
  eL2 = getEventElement(e2);
  if (siw && siw.selectingSomething) {
    selectFromMouseClick();
  }
}//handleClick()

function handleMouseOver(event) {
  e = getEvent(event);
  eL = getEventElement(e);
  if (siw && (mEl = isWithinNode(eL,null,"matchedSmartInputItem",null,null))) {
    siw.selectingSomething = true;
    selectFromMouseOver(mEl);
  } else if (isWithinNode(eL,null,"siwCredit",null,null)) {
    siw.selectingSomething = true;
  }else if (siw) {
    siw.selectingSomething = false;
  }
}//handleMouseOver


// @author Rob W       http://stackoverflow.com/users/938089/rob-w
// @name               getTextBoundingRect
// @param input          Required HTMLElement with `value` attribute
// @param selectionStart Optional number: Start offset. Default 0
// @param selectionEnd   Optional number: End offset. Default selectionStart
// @param debug          Optional boolean. If true, the created test layer
//                         will not be removed.
function getTextBoundingRect(input, selectionStart, selectionEnd, debug) {
    // Basic parameter validation
    if(!input || !('value' in input)) return input;
    if(typeof selectionStart == "string") selectionStart = parseFloat(selectionStart);
    if(typeof selectionStart != "number" || isNaN(selectionStart)) {
        selectionStart = 0;
    }
    if(selectionStart < 0) selectionStart = 0;
    else selectionStart = Math.min(input.value.length, selectionStart);
    if(typeof selectionEnd == "string") selectionEnd = parseFloat(selectionEnd);
    if(typeof selectionEnd != "number" || isNaN(selectionEnd) || selectionEnd < selectionStart) {
        selectionEnd = selectionStart;
    }
    if (selectionEnd < 0) selectionEnd = 0;
    else selectionEnd = Math.min(input.value.length, selectionEnd);

    // If available (thus IE), use the createTextRange method
    if (typeof input.createTextRange == "function") {
        var range = input.createTextRange();
        range.collapse(true);
        range.moveStart('character', selectionStart);
        range.moveEnd('character', selectionEnd - selectionStart);
        return range.getBoundingClientRect();
    }
    // createTextRange is not supported, create a fake text range
    var offset = getInputOffset(),
        topPos = offset.top,
        leftPos = offset.left,
        width = getInputCSS('width', true),
        height = getInputCSS('height', true);

        // Styles to simulate a node in an input field
    var cssDefaultStyles = "white-space:pre;padding:0;margin:0;",
        listOfModifiers = ['direction', 'font-family', 'font-size', 'font-size-adjust', 'font-variant', 'font-weight', 'font-style', 'letter-spacing', 'line-height', 'text-align', 'text-indent', 'text-transform', 'word-wrap', 'word-spacing'];

    topPos += getInputCSS('padding-top', true);
    topPos += getInputCSS('border-top-width', true);
    leftPos += getInputCSS('padding-left', true);
    leftPos += getInputCSS('border-left-width', true);
    leftPos += 1; //Seems to be necessary

    for (var i=0; i<listOfModifiers.length; i++) {
        var property = listOfModifiers[i];
        cssDefaultStyles += property + ':' + getInputCSS(property) +';';
    }
    // End of CSS variable checks

    var text = input.value,
        textLen = text.length,
        fakeClone = document.createElement("div");
    if(selectionStart > 0) appendPart(0, selectionStart);
    var fakeRange = appendPart(selectionStart, selectionEnd);
    if(textLen > selectionEnd) appendPart(selectionEnd, textLen);

    // Styles to inherit the font styles of the element
    fakeClone.style.cssText = cssDefaultStyles;

    // Styles to position the text node at the desired position
    fakeClone.style.position = "absolute";
    fakeClone.style.top = topPos + "px";
    fakeClone.style.left = leftPos + "px";
    fakeClone.style.width = width + "px";
    fakeClone.style.height = height + "px";
    document.body.appendChild(fakeClone);
    var returnValue = fakeRange.getBoundingClientRect(); //Get rect

    if (!debug) fakeClone.parentNode.removeChild(fakeClone); //Remove temp
    return returnValue;

    // Local functions for readability of the previous code
    function appendPart(start, end){
        var span = document.createElement("span");
        span.style.cssText = cssDefaultStyles; //Force styles to prevent unexpected results
        span.textContent = text.substring(start, end);
        fakeClone.appendChild(span);
        return span;
    }
    // Computing offset position
    function getInputOffset(){
        var body = document.body,
            win = document.defaultView,
            docElem = document.documentElement,
            box = document.createElement('div');
        box.style.paddingLeft = box.style.width = "1px";
        body.appendChild(box);
        var isBoxModel = box.offsetWidth == 2;
        body.removeChild(box);
        box = input.getBoundingClientRect();
        var clientTop  = docElem.clientTop  || body.clientTop  || 0,
            clientLeft = docElem.clientLeft || body.clientLeft || 0,
            scrollTop  = win.pageYOffset || isBoxModel && docElem.scrollTop  || body.scrollTop,
            scrollLeft = win.pageXOffset || isBoxModel && docElem.scrollLeft || body.scrollLeft;
        return {
            top : box.top  + scrollTop  - clientTop,
            left: box.left + scrollLeft - clientLeft};
    }
    function getInputCSS(prop, isnumber){
        var val = document.defaultView.getComputedStyle(input, null).getPropertyValue(prop);
        return isnumber ? parseFloat(val) : val;
    }
}

function getCaret(el) { 
  if (el.selectionStart) { 
    return el.selectionStart; 
  } else if (document.selection) { 
    el.focus(); 

    var r = document.selection.createRange(); 
    if (r == null) { 
      return 0; 
    } 

    var re = el.createTextRange(), 
        rc = re.duplicate(); 
    re.moveToBookmark(r.getBookmark()); 
    rc.setEndPoint('EndToStart', re); 

    return rc.text.length; 
  }  
  return 0; 
}

function showSmartInputFloater() {
if (!siw.floater.style.display || (siw.floater.style.display=="none")) {
  if (!siw.customFloater) {
    rectangle = getTextBoundingRect(siw.inputBox, getCaret(siw.inputBox));
    siw.floater.style.left = rectangle.right + 'px';
    siw.floater.style.top = rectangle.bottom + 'px';
  } else {
  //you may
  //do additional things for your custom floater
  //beyond setting display and visibility
  }
  siw.floater.style.display="block";
  siw.floater.style.visibility="visible";
}
}//showSmartInputFloater()

function hideSmartInputFloater() {
if (siw) {
siw.floater.style.display="none";
siw.floater.style.visibility="hidden";
siw = null;
}//siw exists
}//hideSmartInputFloater

function processSmartInput(inputBox) {
if (!siw) siw = new smartInputWindow();
siw.inputBox = inputBox;

classData = inputBox.className.split(" ");
siwDirectives = null;
for (i=0;(!siwDirectives && classData[i]);i++) {
  if (classData[i].indexOf("wickEnabled") != -1)
    siwDirectives = classData[i];
}

if (siwDirectives && (siwDirectives.indexOf(":") != -1)) {
siw.customFloater = true;
newFloaterId = siwDirectives.split(":")[1];
siw.floater = document.getElementById(newFloaterId);
siw.floaterContent = siw.floater.getElementsByTagName("div")[0];
}


setSmartInputData();
if (siw.matchCollection && (siw.matchCollection.length > 0)) selectSmartInputMatchItem(0);
content = getSmartInputBoxContent();
if (content) {
  modifySmartInputBoxContent(content);
  showSmartInputFloater();
} else hideSmartInputFloater();
}//processSmartInput()

function smartInputMatch(cleanValue, value) {
  this.cleanValue = cleanValue;
  this.value = value;
  this.isSelected = false;
}//smartInputMatch

function simplify(s) {
return s.toLowerCase().replace(/^[ \s\f\t\n\r]+/,'').replace(/[ \s\f\t\n\r]+$/,'');
//.replace(/[Ž,,,‘,\u00E9,\u00E8,\u00EA,\u00EB]/gi,"e").replace(/[ˆ,‰,\u00E0,\u00E2]/gi,"a").
}//simplify

function getUserInputToMatch(s) {
a = s;
fields = s.split(" "); // Comma
if (fields.length > 0) a = fields[fields.length - 1];
return a;
}//getUserInputToMatch

function getUserInputBase() {
s = siw.inputBox.value;
a = s;
if (s.trim().lastIndexOf(" ") != -1) {
  a = a.replace(/^([\s\S]*[\r\n\t\f\s]+)[\s\S]*$/i,'$1'); // Comma
  // alert(a);
}
else
  a = "";
return a;
}//getUserInputBase()

function runMatchingLogic(userInput, standalone) {
  userInput = simplify(userInput);
  uifc = userInput.charAt(0).toLowerCase();
  if (uifc == '"') uifc = (n = userInput.charAt(1)) ? n.toLowerCase() : "z";
  if (standalone) userInput = uifc;
  if (siw) siw.matchCollection = new Array();
  pointerToCollectionToUse = collection;
  if (siw && siw.revisedCollection && (siw.revisedCollection.length > 0) && siw.lastUserInput && (userInput.indexOf(siw.lastUserInput) == 0)) {
    pointerToCollectionToUse = siw.revisedCollection;
  } else if (collectionIndex[userInput] && (collectionIndex[userInput].length > 0)) {
    pointerToCollectionToUse = collectionIndex[userInput];
  } else if (collectionIndex[uifc] && (collectionIndex[uifc].length > 0)) {
    pointerToCollectionToUse = collectionIndex[uifc];
  } else if (siw && (userInput.length == 1) && (!collectionIndex[uifc])) {
    siw.buildIndex = true;
  } else if (siw) {
    siw.buildIndex = false;
  }
  
  tempCollection = new Array();

  re1m = new RegExp("^([ \"\>\<\-]*)("+userInput+")","i");
  re2m = new RegExp("([ \"\>\<\-]+)("+userInput+")","i");
  re1 = new RegExp("^([ \"\}\{\-]*)("+userInput+")","gi");
  re2 = new RegExp("([ \"\}\{\-]+)("+userInput+")","gi");
  
  for (i=0,j=0;(i<pointerToCollectionToUse.length);i++) {
    displayMatches = ((!standalone) && (j < siw.MAX_MATCHES));
    entry = pointerToCollectionToUse[i];
    mEntry = simplify(entry);
    if (!standalone && (mEntry.indexOf(userInput) == 0)) {
      userInput = userInput.replace(/\>/gi,'\\}').replace(/\< ?/gi,'\\{');
      re = new RegExp("(" + userInput + ")","i");
      if (displayMatches) {
        siw.matchCollection[j] = new smartInputMatch(entry, mEntry.replace(/\>/gi,'}').replace(/\< ?/gi,'{').replace(re,"<b>$1</b>"));
      }
      tempCollection[j] = entry;
      j++;    
    } else if (mEntry.match(re1m) || mEntry.match(re2m)) {
      if (!standalone && displayMatches) {
        siw.matchCollection[j] = new smartInputMatch(entry, mEntry.replace(/\>/gi,'}').replace(/\</gi,'{').replace(re1,"$1<b>$2</b>").replace(re2,"$1<b>$2</b>"));
      }
      tempCollection[j] = entry;
      j++;
    }
  }//loop thru collection
  if (siw) {
    siw.lastUserInput = userInput;
    siw.revisedCollection = tempCollection.join(",").split(",");
    collectionIndex[userInput] = tempCollection.join(",").split(",");
  }
  if (standalone || siw.buildIndex) {
    collectionIndex[uifc] = tempCollection.join(",").split(",");
    if (siw) siw.buildIndex = false;
  }
}//runMatchingLogic

function setSmartInputData() {
if (siw) {
orgUserInput = siw.inputBox.value;
orgUserInput = getUserInputToMatch(orgUserInput);
userInput = orgUserInput.toLowerCase().replace(/[\r\n\t\f\s]+/gi,' ').replace(/^ +/gi,'').replace(/ +$/gi,'').replace(/ +/gi,' ').replace(/\\/gi,'').replace(/\[/gi,'').replace(/\(/gi,'').replace(/\./gi,'\.').replace(/\?/gi,'');
if (userInput && (userInput != "") && (userInput != '"')) {
  runMatchingLogic(userInput);
}//if userinput not blank and is meaningful
else {
siw.matchCollection = null;
}
}//siw exists ... uhmkaaayyyyy
}//setSmartInputData

function getSmartInputBoxContent() {
a = null;
if (siw && siw.matchCollection && (siw.matchCollection.length > 0)) {
a = '';
for (i = 0;i < siw.matchCollection.length; i++) {
selectedString = siw.matchCollection[i].isSelected ? ' selectedSmartInputItem' : '';
a += '<p class="matchedSmartInputItem' + selectedString + '">' + siw.matchCollection[i].value.replace(/\{ */gi,"&lt;").replace(/\} */gi,"&gt;") + '</p>';
}//
}//siw exists
return a;
}//getSmartInputBoxContent

function modifySmartInputBoxContent(content) {
//todo: remove credits 'cuz no one gives a shit ;] - done
siw.floaterContent.innerHTML = '<div id="smartInputResults">' + content + (siw.showCredit ? ('<p class="siwCredit">Powered By: <a target="PhrawgBlog" href="http://chrisholland.blogspot.com/?from=smartinput&ref='+escape(location.href)+'">Chris Holland</a></p>') : '') +'</div>';
siw.matchListDisplay = document.getElementById("smartInputResults");
}//modifySmartInputBoxContent()

function selectFromMouseOver(o) {
currentIndex = getCurrentlySelectedSmartInputItem();
if (currentIndex != null) deSelectSmartInputMatchItem(currentIndex);
newIndex = getIndexFromElement(o);
selectSmartInputMatchItem(newIndex);
modifySmartInputBoxContent(getSmartInputBoxContent());
}//selectFromMouseOver

function selectFromMouseClick() {
activateCurrentSmartInputMatch();
siw.inputBox.focus();
hideSmartInputFloater();
}//selectFromMouseClick

function getIndexFromElement(o) {
index = 0;
while(o = o.previousSibling) {
index++;
}//
return index;
}//getIndexFromElement

function getCurrentlySelectedSmartInputItem() {
answer = null;
for (i = 0; ((i < siw.matchCollection.length) && !answer) ; i++) {
  if (siw.matchCollection[i].isSelected)
    answer = i;
}//
return answer;
}//getCurrentlySelectedSmartInputItem

function selectSmartInputMatchItem(index) {
  siw.matchCollection[index].isSelected = true;
}//selectSmartInputMatchItem()

function deSelectSmartInputMatchItem(index) {
  siw.matchCollection[index].isSelected = false;
}//deSelectSmartInputMatchItem()

function selectNextSmartInputMatchItem() {
currentIndex = getCurrentlySelectedSmartInputItem();
if (currentIndex != null) {
  deSelectSmartInputMatchItem(currentIndex);
  if ((currentIndex + 1) < siw.matchCollection.length)
    selectSmartInputMatchItem(currentIndex + 1);
  else
    selectSmartInputMatchItem(0);
} else {
  selectSmartInputMatchItem(0);
}
modifySmartInputBoxContent(getSmartInputBoxContent());
}//selectNextSmartInputMatchItem

function selectPreviousSmartInputMatchItem() {
currentIndex = getCurrentlySelectedSmartInputItem();
if (currentIndex != null) {
  deSelectSmartInputMatchItem(currentIndex);
  if ((currentIndex - 1) >= 0)
    selectSmartInputMatchItem(currentIndex - 1);
  else
    selectSmartInputMatchItem(siw.matchCollection.length - 1);
} else {
  selectSmartInputMatchItem(siw.matchCollection.length - 1);
}
modifySmartInputBoxContent(getSmartInputBoxContent());
}//selectPreviousSmartInputMatchItem

function activateCurrentSmartInputMatch() {
  baseValue = getUserInputBase();
  if ((selIndex = getCurrentlySelectedSmartInputItem()) != null) {
    addedValue = siw.matchCollection[selIndex].cleanValue;
    theString = (baseValue ? baseValue : "") + addedValue + " "; //Remove a comma
    siw.inputBox.value = theString;
    runMatchingLogic(addedValue, true);
  }
}//activateCurrentSmartInputMatch

function smartInputWindow () {
  this.customFloater = false;
  this.floater = document.getElementById("smartInputFloater");
  this.floaterContent = document.getElementById("smartInputFloaterContent");
  this.selectedSmartInputItem = null;
  this.MAX_MATCHES = 15;
  this.isGecko = (navigator.userAgent.indexOf("Gecko/200") != -1);
  this.isSafari = (navigator.userAgent.indexOf("Safari") != -1);
  this.isWinIE = ((navigator.userAgent.indexOf("Win") != -1 ) && (navigator.userAgent.indexOf("MSIE") != -1 ));
  this.showCredit = false;
}//smartInputWindow Object

function registerSmartInputListeners() {
inputs = document.getElementsByTagName("input");
texts = document.getElementsByTagName("textarea");
allinputs = new Array();
z = 0;
y = 0;
while(inputs[z]) {
allinputs[z] = inputs[z];
z++;
}//
while(texts[y]) {
allinputs[z] = texts[y];
z++;
y++;
}//

for (i=0; i < allinputs.length;i++) {
  if ((c = allinputs[i].className) && (c == "wickEnabled")) {
    allinputs[i].setAttribute("autocomplete","OFF");
    allinputs[i].onfocus = handleFocus;
    allinputs[i].onblur = handleBlur;
    allinputs[i].onkeydown = handleKeyDown;
    allinputs[i].onkeyup = handleKeyPress;
  }
}//loop thru inputs
}//registerSmartInputListeners

siw = null;

if (document.addEventListener) {
  document.addEventListener("keydown", handleKeyDown, false);
  document.addEventListener("keyup", handleKeyPress, false);
  document.addEventListener("mouseup", handleClick, false);
  document.addEventListener("mouseover", handleMouseOver, false);
} else {
  document.onkeydown = handleKeyDown;
  document.onkeyup = handleKeyPress;
  document.onmouseup = handleClick;
  document.onmouseover = handleMouseOver;
}

registerSmartInputListeners();

document.write (
'<table id="smartInputFloater" class="floater" cellpadding="0" cellspacing="0"><tr><td id="smartInputFloaterContent" nowrap="nowrap">'
+'<\/td><\/tr><\/table>'
);

//note: instruct users to the fact that no commas should be present in entries.
//it would make things insanely messy.
//this is why i'm filtering commas here:
for (x=0;x<collection.length;x++) {
collection[x] = collection[x].replace(/\,/gi,'');
}//

collectionIndex = new Array();

ds = "";
function debug(s) {
ds += ( s + "\n");
}
// End of WICK

<?php if($is_sht){?>
function chkall(cab){
 var e=document.DF.elements;
 if (e!=null){
  var cl=e.length;
  for (i=0;i<cl;i++){var m=e[i];if(m.checked!=null && m.type=="checkbox"){m.checked=cab.checked}}
 }
}
function sht(f){
 document.DF.dosht.value=f;
}

<?php }?>
</script>

</head>
<body onload="after_load()">
<form method="post" name="DF" action="<?php echo $self?>" enctype="multipart/form-data">
<input type="hidden" name="XSS" value="<?php echo $_SESSION['XSS']?>">
<input type="hidden" name="refresh" value="">
<input type="hidden" name="p" value="">

<div class="inv">
<a href="http://phpminiadmin.sourceforge.net/" target="_blank"><b>phpMiniAdmin <?php echo $VERSION?></b></a>
<?php if ($_SESSION['is_logged'] && $dbh){ ?>
 | <a href="?<?php echo $xurl?>&q=show+databases">Databases</a>: <select name="db" onChange="frefresh()"><option value='*'> - select/refresh -</option><option value=''> - show all -</option><?php echo get_db_select($dbn)?></select>
<?php if($dbn){ $z=" &#183; <a href='$self?$xurl&db=$dbn"; ?>
<?php echo $z.'&q='.urlencode($SHOW_T)?>'>show tables</a>
<?php echo $z?>&shex=1'>export</a>
<?php echo $z?>&shim=1'>import</a>
<?php } ?>
 | <a href="?showcfg=1">Settings</a>
<?php } ?>
<?php if ($GLOBALS['ACCESS_PWD']){?> | <a href="?<?php echo $xurl?>&logoff=1" onclick="logoff()">Logoff</a> <?php }?>
 | <a href="?phpinfo=1">phpinfo</a>
</div>

<div class="err"><?php echo $err_msg?></div>

<?php
}

function print_screen(){
 global $out_message, $SQLq, $err_msg, $reccount, $time_all, $sqldr, $page, $MAX_ROWS_PER_PAGE, $is_limited_sql;

 $nav='';
 if ($is_limited_sql && ($page || $reccount>=$MAX_ROWS_PER_PAGE) ){
  $nav="<div class='nav'>".get_nav($page, 10000, $MAX_ROWS_PER_PAGE, "javascript:go(%p%)")."</div>";
 }

 print_header();
?>

<div class="dot" style="padding:0 0 5px 20px">
SQL-query (or multiple queries separated by ";"):&nbsp;<button type="button" class="qnav" onclick="q_prev()">&lt;</button><button type="button" class="qnav" onclick="q_next()">&gt;</button><br>
<textarea id="q" name="q" cols="70" rows="10" style="width:98%" class="wickEnabled"><?php echo $SQLq?></textarea><br>
<input type="submit" name="GoSQL" value="Go" onclick="return chksql()" style="width:100px">&nbsp;&nbsp;
<input type="button" name="Clear" value=" Clear " onclick="document.DF.q.value=''" style="width:100px">
</div>

<div class="dot" style="padding:5px 0 5px 20px">
Records: <b><?php echo $reccount?></b> in <b><?php echo $time_all?></b> sec<br>
<b><?php echo $out_message?></b>
</div>
<div class="sqldr">
<?php echo $nav.$sqldr.$nav; ?>
</div>
<?php
 print_footer();
}

function print_footer(){
?>
</form>
<div class="ft">
&copy; 2004-2012 <a href="http://osalabs.com" target="_blank">Oleg Savchuk</a>
</div>
</body></html>
<?php
}

function print_login(){
 print_header();
?>
<center>
<h3>Access protected by password</h3>
<div style="width:400px;border:1px solid #999999;background-color:#eeeeee">
Password: <input type="password" name="pwd" value="">
<input type="hidden" name="login" value="1">
<input type="submit" value=" Login ">
</div>
</center>
<?php
 print_footer();
}


function print_cfg(){
 global $DB,$err_msg,$self;
 print_header();
?>
<center>
<h3>DB Connection Settings</h3>
<div class="frm">
<label class="l">DB user name:</label><input type="text" name="v[user]" value="<?php echo $DB['user']?>"><br>
<label class="l">Password:</label><input type="password" name="v[pwd]" value="<?php echo $DB['pwd']?>"><br>
<div style="text-align:right"><a href="#" class="ajax" onclick="cfg_toggle()">advanced settings</a></div>
<div id="cfg-adv" style="display:none;">
<label class="l">DB name:</label><input type="text" name="v[db]" value="<?php echo $DB['db']?>"><br>
<label class="l">MySQL host:</label><input type="text" name="v[host]" value="<?php echo $DB['host']?>"> port: <input type="text" name="v[port]" value="<?php echo $DB['port']?>" size="4"><br>
<label class="l">Charset:</label><select name="v[chset]"><option value="">- default -</option><?php echo chset_select($DB['chset'])?></select><br>
<br><input type="checkbox" name="rmb" value="1" checked> Remember in cookies for 30 days
</div>
<center>
<input type="hidden" name="savecfg" value="1">
<input type="submit" value=" Apply "><input type="button" value=" Cancel " onclick="window.location='<?php echo $self?>'">
</center>
</div>
</center>
<?php
 print_footer();
}


//* utilities
function db_connect($nodie=0){
 global $dbh,$DB,$err_msg;

 $dbh=@mysql_connect($DB['host'].($DB['port']?":$DB[port]":''),$DB['user'],$DB['pwd']);
 if (!$dbh) {
    $err_msg='Cannot connect to the database because: '.mysql_error();
    if (!$nodie) die($err_msg);
 }

 if ($dbh && $DB['db']) {
  $res=mysql_select_db($DB['db'], $dbh);
  if (!$res) {
     $err_msg='Cannot select db because: '.mysql_error();
     if (!$nodie) die($err_msg);
  }else{
     if ($DB['chset']) db_query("SET NAMES ".$DB['chset']);
  }
 }

 return $dbh;
}

function db_checkconnect($dbh1=NULL, $skiperr=0){
 global $dbh;
 if (!$dbh1) $dbh1=&$dbh;
 if (!$dbh1 or !mysql_ping($dbh1)) {
    db_connect($skiperr);
    $dbh1=&$dbh;
 }
 return $dbh1;
}

function db_disconnect(){
 global $dbh;
 mysql_close($dbh);
}

function dbq($s){
 global $dbh;
 if (is_null($s)) return "NULL";
 return "'".mysql_real_escape_string($s,$dbh)."'";
}

function db_query($sql, $dbh1=NULL, $skiperr=0){
 $dbh1=db_checkconnect($dbh1, $skiperr);
 $sth=@mysql_query($sql, $dbh1);
 if (!$sth && $skiperr) return;
 if (!$sth) die("Error in DB operation:<br>\n".mysql_error($dbh1)."<br>\n$sql");
 return $sth;
}

function db_array($sql, $dbh1=NULL, $skiperr=0, $isnum=0){#array of rows
 $sth=db_query($sql, $dbh1, $skiperr);
 if (!$sth) return;
 $res=array();
 if ($isnum){
   while($row=mysql_fetch_row($sth)) $res[]=$row;
 }else{
   while($row=mysql_fetch_assoc($sth)) $res[]=$row;
 }
 return $res;
}

function db_row($sql){
 $sth=db_query($sql);
 return mysql_fetch_assoc($sth);
}

function db_value($sql){
 $sth=db_query($sql);
 $row=mysql_fetch_row($sth);
 return $row[0];
}

function get_identity($dbh1=NULL){
 $dbh1=db_checkconnect($dbh1);
 return mysql_insert_id($dbh1);
}

function get_db_select($sel=''){
 global $DB;
 if (is_array($_SESSION['sql_sd']) && $_REQUEST['db']!='*'){//check cache
    $arr=$_SESSION['sql_sd'];
 }else{
   $arr=db_array("show databases",NULL,1);
   if (!is_array($arr)){
      $arr=array( 0 => array('Database' => $DB['db']) );
    }
   $_SESSION['sql_sd']=$arr;
 }
 return @sel($arr,'Database',$sel);
}

function chset_select($sel=''){
 global $DBDEF;
 $result='';
 if ($_SESSION['sql_chset']){
    $arr=$_SESSION['sql_chset'];
 }else{
   $arr=db_array("show character set",NULL,1);
   if (!is_array($arr)) $arr=array(array('Charset'=>$DBDEF['chset']));
   $_SESSION['sql_chset']=$arr;
 }

 return @sel($arr,'Charset',$sel);
}

function sel($arr,$n,$sel=''){
 foreach($arr as $a){
#   echo $a[0];
   $b=$a[$n];
   $res.="<option value='$b' ".($sel && $sel==$b?'selected':'').">$b</option>";
 }
 return $res;
}

function microtime_float(){
 list($usec,$sec)=explode(" ",microtime());
 return ((float)$usec+(float)$sec);
}

/* page nav
 $pg=int($_[0]);     #current page
 $all=int($_[1]);     #total number of items
 $PP=$_[2];      #number if items Per Page
 $ptpl=$_[3];      #page url /ukr/dollar/notes.php?page=    for notes.php
 $show_all=$_[5];           #print Totals?
*/
function get_nav($pg, $all, $PP, $ptpl, $show_all=''){
  $n='&nbsp;';
  $sep=" $n|$n\n";
  if (!$PP) $PP=10;
  $allp=floor($all/$PP+0.999999);

  $pname='';
  $res='';
  $w=array('Less','More','Back','Next','First','Total');

  $sp=$pg-2;
  if($sp<0) $sp=0;
  if($allp-$sp<5 && $allp>=5) $sp=$allp-5;

  $res="";

  if($sp>0){
    $pname=pen($sp-1,$ptpl);
    $res.="<a href='$pname'>$w[0]</a>";
    $res.=$sep;
  }
  for($p_p=$sp;$p_p<$allp && $p_p<$sp+5;$p_p++){
     $first_s=$p_p*$PP+1;
     $last_s=($p_p+1)*$PP;
     $pname=pen($p_p,$ptpl);
     if($last_s>$all){
       $last_s=$all;
     }
     if($p_p==$pg){
        $res.="<b>$first_s..$last_s</b>";
     }else{
        $res.="<a href='$pname'>$first_s..$last_s</a>";
     }
     if($p_p+1<$allp) $res.=$sep;
  }
  if($sp+5<$allp){
    $pname=pen($sp+5,$ptpl);
    $res.="<a href='$pname'>$w[1]</a>";
  }
  $res.=" <br>\n";

  if($pg>0){
    $pname=pen($pg-1,$ptpl);
    $res.="<a href='$pname'>$w[2]</a> $n|$n ";
    $pname=pen(0,$ptpl);
    $res.="<a href='$pname'>$w[4]</a>";
  }
  if($pg>0 && $pg+1<$allp) $res.=$sep;
  if($pg+1<$allp){
    $pname=pen($pg+1,$ptpl);
    $res.="<a href='$pname'>$w[3]</a>";
  }
  if ($show_all) $res.=" <b>($w[5] - $all)</b> ";

  return $res;
}

function pen($p,$np=''){
 return str_replace('%p%',$p, $np);
}

function killmq($value){
 return is_array($value)?array_map('killmq',$value):stripslashes($value);
}

function savecfg(){
 $v=$_REQUEST['v'];
 $_SESSION['DB']=$v;
 unset($_SESSION['sql_sd']);

 if ($_REQUEST['rmb']){
    $tm=time()+60*60*24*30;
    setcookie("conn[db]",  $v['db'],$tm);
    setcookie("conn[user]",$v['user'],$tm);
    setcookie("conn[pwd]", $v['pwd'],$tm);
    setcookie("conn[host]",$v['host'],$tm);
    setcookie("conn[port]",$v['port'],$tm);
    setcookie("conn[chset]",$v['chset'],$tm);
 }else{
    setcookie("conn[db]",  FALSE,-1);
    setcookie("conn[user]",FALSE,-1);
    setcookie("conn[pwd]", FALSE,-1);
    setcookie("conn[host]",FALSE,-1);
    setcookie("conn[port]",FALSE,-1);
    setcookie("conn[chset]",FALSE,-1);
 }
}

//during login only - from cookies or use defaults;
function loadcfg(){
 global $DBDEF;

 if( isset($_COOKIE['conn']) ){
    $a=$_COOKIE['conn'];
    $_SESSION['DB']=$_COOKIE['conn'];
 }else{
    $_SESSION['DB']=$DBDEF;
 }
 if (!strlen($_SESSION['DB']['chset'])) $_SESSION['DB']['chset']=$DBDEF['chset'];#don't allow empty charset
}

//each time - from session to $DB_*
function loadsess(){
 global $DB;

 $DB=$_SESSION['DB'];

 $rdb=$_REQUEST['db'];
 if ($rdb=='*') $rdb='';
 if ($rdb) {
    $DB['db']=$rdb;
 }
}

function print_export(){
 global $self,$xurl,$DB;
 $t=$_REQUEST['t'];
 $l=($t)?"Table $t":"whole DB";
 print_header();
?>
<center>
<h3>Export <?php echo $l?></h3>
<div class="frm">
<input type="checkbox" name="s" value="1" checked> Structure<br>
<input type="checkbox" name="d" value="1" checked> Data<br><br>
<div><label><input type="radio" name="et" value="" checked> .sql</label>&nbsp;</div>
<div>
<?php if ($t && !strpos($t,',')){?>
 <label><input type="radio" name="et" value="csv"> .csv (Excel style, data only and for one table only)</label>
<?php }else{?>
<label>&nbsp;( ) .csv</label> <small>(to export as csv - go to 'show tables' and export just ONE table)</small>
<?php }?>
</div>
<br>
<div><label><input type="checkbox" name="gz" value="1"> compress as .gz</label></div>
<br>
<input type="hidden" name="doex" value="1">
<input type="hidden" name="t" value="<?php echo $t?>">
<input type="submit" value=" Download "><input type="button" value=" Cancel " onclick="window.location='<?php echo $self.'?'.$xurl.'&db='.$DB['db']?>'">
</div>
</center>
<?php
 print_footer();
 exit;
}

function do_export(){
 global $DB,$VERSION,$D,$BOM,$ex_isgz;
 $rt=str_replace('`','',$_REQUEST['t']);
 $t=explode(",",$rt);
 $th=array_flip($t);
 $ct=count($t);
 $z=db_row("show variables like 'max_allowed_packet'");
 $MAXI=floor($z['Value']*0.8);
 if(!$MAXI)$MAXI=838860;
 $aext='';$ctp='';

 $ex_isgz=($_REQUEST['gz'])?1:0;
 if ($ex_isgz) {
    $aext='.gz';$ctp='application/x-gzip';
 }
 ex_start();

 if ($ct==1&&$_REQUEST['et']=='csv'){
  ex_hdr($ctp?$ctp:'text/csv',"$t[0].csv$aext");
  if ($DB['chset']=='utf8') ex_end($BOM);

  $sth=db_query("select * from `$t[0]`");
  $fn=mysql_num_fields($sth);
  for($i=0;$i<$fn;$i++){
   $m=mysql_fetch_field($sth,$i);
   ex_w(qstr($m->name).(($i<$fn-1)?",":""));
  }
  ex_w($D);
  while($row=mysql_fetch_row($sth)) ex_w(to_csv_row($row));
  ex_end();
  exit;
 }

 ex_hdr($ctp?$ctp:'text/plain',"$DB[db]".(($ct==1&&$t[0])?".$t[0]":(($ct>1)?'.'.$ct.'tables':'')).".sql$aext");
 ex_w("-- phpMiniAdmin dump $VERSION$D-- Datetime: ".date('Y-m-d H:i:s')."$D-- Host: $DB[host]$D-- Database: $DB[db]$D$D");
 ex_w("/*!40030 SET NAMES $DB[chset] */;$D/*!40030 SET GLOBAL max_allowed_packet=16777216 */;$D$D");

 $sth=db_query("show tables from `$DB[db]`");
 while($row=mysql_fetch_row($sth)){
   if (!$rt||array_key_exists($row[0],$th)) do_export_table($row[0],1,$MAXI);
 }

 ex_w("$D-- phpMiniAdmin dump end$D");
 ex_end();
 exit;
}

function do_export_table($t='',$isvar=0,$MAXI=838860){
 global $D;
 @set_time_limit(600);

 if($_REQUEST['s']){
  $sth=db_query("show create table `$t`");
  $row=mysql_fetch_row($sth);
  $ct=preg_replace("/\n\r|\r\n|\n|\r/",$D,$row[1]);
  ex_w("DROP TABLE IF EXISTS `$t`;$D$ct;$D$D");
 }

 if ($_REQUEST['d']){
  $exsql='';
  ex_w("/*!40000 ALTER TABLE `$t` DISABLE KEYS */;$D");
  $sth=db_query("select * from `$t`");
  while($row=mysql_fetch_row($sth)){
    $values='';
    foreach($row as $v) $values.=(($values)?',':'').dbq($v);
    $exsql.=(($exsql)?',':'')."(".$values.")";
    if (strlen($exsql)>$MAXI) {
       ex_w("INSERT INTO `$t` VALUES $exsql;$D");$exsql='';
    }
  }
  if ($exsql) ex_w("INSERT INTO `$t` VALUES $exsql;$D");
  ex_w("/*!40000 ALTER TABLE `$t` ENABLE KEYS */;$D$D");
 }
 flush();
}

function ex_hdr($ct,$fn){
 header("Content-type: $ct");
 header("Content-Disposition: attachment; filename=\"$fn\"");
}
function ex_start(){
 global $ex_isgz,$ex_gz,$ex_tmpf;
 if ($ex_isgz){
    $ex_tmpf=tempnam(sys_get_temp_dir(),'pma').'.gz';
    if (!($ex_gz=gzopen($ex_tmpf,'wb9'))) die("Error trying to create gz tmp file");
 }
}
function ex_w($s){
 global $ex_isgz,$ex_gz;
 if ($ex_isgz){
    gzwrite($ex_gz,$s,strlen($s));
 }else{
    echo $s;
 }
}
function ex_end(){
 global $ex_isgz,$ex_gz,$ex_tmpf;
 if ($ex_isgz){
    gzclose($ex_gz);
    readfile($ex_tmpf);
 }
}

function print_import(){
 global $self,$xurl,$DB;
 print_header();
?>
<center>
<h3>Import DB</h3>
<div class="frm">
<b>.sql</b> or <b>.gz</b> file: <input type="file" name="file1" value="" size=40><br>
<input type="hidden" name="doim" value="1">
<input type="submit" value=" Upload and Import " onclick="return ays()"><input type="button" value=" Cancel " onclick="window.location='<?php echo $self.'?'.$xurl.'&db='.$DB['db']?>'">
</div>
<br><br><br>
<!--
<h3>Import one Table from CSV</h3>
<div class="frm">
.csv file (Excel style): <input type="file" name="file2" value="" size=40><br>
<input type="checkbox" name="r1" value="1" checked> first row contain field names<br>
<small>(note: for success, field names should be exactly the same as in DB)</small><br>
Character set of the file: <select name="chset"><?php echo chset_select('utf8')?></select>
<br><br>
Import into:<br>
<input type="radio" name="tt" value="1" checked="checked"> existing table:
 <select name="t">
 <option value=''>- select -</option>
 <?php echo sel(db_array('show tables',NULL,0,1), 0, ''); ?>
</select>
<div style="margin-left:20px">
 <input type="checkbox" name="ttr" value="1"> replace existing DB data<br>
 <input type="checkbox" name="tti" value="1"> ignore duplicate rows
</div>
<input type="radio" name="tt" value="2"> create new table with name <input type="text" name="tn" value="" size="20">
<br><br>
<input type="hidden" name="doimcsv" value="1">
<input type="submit" value=" Upload and Import " onclick="return ays()"><input type="button" value=" Cancel " onclick="window.location='<?php echo $self?>'">
</div>
-->
</center>
<?php
 print_footer();
 exit;
}

function do_import(){
 global $err_msg,$out_message,$dbh,$SHOW_T;
 $err_msg='';
 $F=$_FILES['file1'];

 if ($F && $F['name']){
  $filename=$F['tmp_name'];
  $pi=pathinfo($F['name']);
  if ($pi['extension']!='sql'){//if not sql - assume .gz
     $tmpf=tempnam(sys_get_temp_dir(),'pma');
     if (($gz=gzopen($filename,'rb')) && ($tf=fopen($tmpf,'wb'))){
        while(!gzeof($gz)){
           if (fwrite($tf,gzread($gz,8192),8192)===FALSE){$err_msg='Error during gz file extraction to tmp file';break;}
        }//extract to tmp file
        gzclose($gz);fclose($tf);$filename=$tmpf;
     }else{$err_msg='Error opening gz file';}
  }
  if (!$err_msg){
   if (!do_multi_sql('', $filename)){
      $err_msg='Import Error: '.mysql_error($dbh);
   }else{
      $out_message='Import done successfully';
      do_sql($SHOW_T);
      return;
  }}
 }else{
  $err_msg="Error: Please select file first";
 }
 print_import();
 exit;
}

// multiple SQL statements splitter
function do_multi_sql($insql,$fname=''){
 @set_time_limit(600);

 $sql='';
 $ochar='';
 $is_cmt='';
 $GLOBALS['insql_done']=0;
 while ($str=get_next_chunk($insql,$fname)){
    $opos=-strlen($ochar);
    $cur_pos=0;
    $i=strlen($str);
    while ($i--){
       if ($ochar){
          list($clchar, $clpos)=get_close_char($str, $opos+strlen($ochar), $ochar);
          if ( $clchar ) {
             if ($ochar=='--' || $ochar=='#' || $is_cmt ){
                $sql.=substr($str, $cur_pos, $opos-$cur_pos );
             }else{
                $sql.=substr($str, $cur_pos, $clpos+strlen($clchar)-$cur_pos );
             }
             $cur_pos=$clpos+strlen($clchar);
             $ochar='';
             $opos=0;
          }else{
             $sql.=substr($str, $cur_pos);
             break;
          }
       }else{
          list($ochar, $opos)=get_open_char($str, $cur_pos);
          if ($ochar==';'){
             $sql.=substr($str, $cur_pos, $opos-$cur_pos+1);
             if (!do_one_sql($sql)) return 0;
             $sql='';
             $cur_pos=$opos+strlen($ochar);
             $ochar='';
             $opos=0;
          }elseif(!$ochar) {
             $sql.=substr($str, $cur_pos);
             break;
          }else{
             $is_cmt=0;if ($ochar=='/*' && substr($str, $opos, 3)!='/*!') $is_cmt=1;
          }
       }
    }
 }

 if ($sql){
    if (!do_one_sql($sql)) return 0;
    $sql='';
 }
 return 1;
}

//read from insql var or file
function get_next_chunk($insql, $fname){
 global $LFILE, $insql_done;
 if ($insql) {
    if ($insql_done){
       return '';
    }else{
       $insql_done=1;
       return $insql;
    }
 }
 if (!$fname) return '';
 if (!$LFILE){
    $LFILE=fopen($fname,"r+b") or die("Can't open [$fname] file $!");
 }
 return fread($LFILE, 64*1024);
}

function get_open_char($str, $pos){
 if ( preg_match("/(\/\*|^--|(?<=\s)--|#|'|\"|;)/", $str, $m, PREG_OFFSET_CAPTURE, $pos) ) {
    $ochar=$m[1][0];
    $opos=$m[1][1];
 }
 return array($ochar, $opos);
}

#RECURSIVE!
function get_close_char($str, $pos, $ochar){
 $aCLOSE=array(
   '\'' => '(?<!\\\\)\'|(\\\\+)\'',
   '"' => '(?<!\\\\)"',
   '/*' => '\*\/',
   '#' => '[\r\n]+',
   '--' => '[\r\n]+',
 );
 if ( $aCLOSE[$ochar] && preg_match("/(".$aCLOSE[$ochar].")/", $str, $m, PREG_OFFSET_CAPTURE, $pos ) ) {
    $clchar=$m[1][0];
    $clpos=$m[1][1];
    $sl=strlen($m[2][0]);
    if ($ochar=="'" && $sl){
       if ($sl % 2){ #don't count as CLOSE char if number of slashes before ' ODD
          list($clchar, $clpos)=get_close_char($str, $clpos+strlen($clchar), $ochar);
       }else{
          $clpos+=strlen($clchar)-1;$clchar="'";#correction
       }
    }
 }
 return array($clchar, $clpos);
}

function do_one_sql($sql){
 global $last_sth,$last_sql,$MAX_ROWS_PER_PAGE,$page,$is_limited_sql;
 $sql=trim($sql);
 $sql=preg_replace("/;$/","",$sql);
 if ($sql){
    $last_sql=$sql;$is_limited_sql=0;
    if (preg_match("/^select/i",$sql) && !preg_match("/limit +\d+/i", $sql)){
       $offset=$page*$MAX_ROWS_PER_PAGE;
       $sql.=" LIMIT $offset,$MAX_ROWS_PER_PAGE";
       $is_limited_sql=1;
    }
    $last_sth=db_query($sql,0,'noerr');
    return $last_sth;
 }
 return 1;
}

function do_sht(){
 global $SHOW_T;
 $cb=$_REQUEST['cb'];
 if (!is_array($cb)) $cb=array();
 switch ($_REQUEST['dosht']){
  case 'exp':$_REQUEST['t']=join(",",$cb);print_export();exit;
  case 'drop':$sq='DROP TABLE';break;
  case 'trunc':$sq='TRUNCATE TABLE';break;
  case 'opt':$sq='OPTIMIZE TABLE';break;
 }
 if ($sq){
  $sql='';
  foreach($cb as $v){
   $sql.=$sq." $v;\n";
  }
  if ($sql) do_sql($sql);
 }
 do_sql($SHOW_T);
}

function to_csv_row($adata){
 global $D;
 $r='';
 foreach ($adata as $a){
   $r.=(($r)?",":"").qstr($a);
 }
 return $r.$D;
}
function qstr($s){
 $s=nl2br($s);
 $s=str_replace('"','""',$s);
 return '"'.$s.'"';
}

function get_rand_str($len){
 $result='';
 $chars=preg_split('//','ABCDEFabcdef0123456789');
 for($i=0;$i<$len;$i++) $result.=$chars[rand(0,count($chars)-1)];
 return $result;
}

function check_xss(){
 global $self;
 if ($_SESSION['XSS']!=trim($_REQUEST['XSS'])){
    unset($_SESSION['XSS']);
    header("location: $self");
    exit;
 }
}

function rw($s){#for debug
 echo $s."<br>\n";
}

?>