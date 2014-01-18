<?php

// Make sure we are called from index.php
if (!defined('SECURITY'))
  die('Hacking attempt');

// twofactor stuff
$cp_editable = $wf_editable = $ea_editable = $wf_sent = $ea_sent = $cp_sent = 0;

// 2fa - set old token so we can use it if an error happens or we need to use post
$oldtoken_ea = (isset($_POST['ea_token']) && $_POST['ea_token'] !== '') ? $_POST['ea_token'] : '';
$oldtoken_cp = (isset($_POST['cp_token']) && $_POST['cp_token'] !== '') ? $_POST['cp_token'] : '';
$oldtoken_wf = (isset($_POST['wf_token']) && $_POST['wf_token'] !== '') ? $_POST['wf_token'] : '';
$updating = (@$_POST['do']) ? 1 : 0;

// csrf stuff 
$csrfenabled = ($config['csrf']['enabled'] && $config['csrf']['options']['sitewide']) ? 1 : 0;
if ($csrfenabled) {
  $nocsrf = ($csrftoken->getBasic($user->getCurrentIP(), 'editaccount', 'mdyH') == @$_POST['ctoken']) ? 1 : 0;
}

if ($user->isAuthenticated()) {
  if ($config['twofactor']['enabled']) {
    $popupmsg = 'E-mail confirmations are required for ';
    $popuptypes = array();
    if ($config['twofactor']['options']['details']) {
      $popuptypes[] = 'editing your details';
      $ea_editable = $user->token->isTokenValid($_SESSION['USERDATA']['id'], $oldtoken_ea, 5);
      $ea_sent = $user->token->doesTokenExist('account_edit', $_SESSION['USERDATA']['id']);
    }
    if ($config['twofactor']['options']['changepw']) {
      $popuptypes[] = 'changing your password';
      $cp_editable = $user->token->isTokenValid($_SESSION['USERDATA']['id'], $oldtoken_cp, 6);
      $cp_sent = $user->token->doesTokenExist('change_pw', $_SESSION['USERDATA']['id']);
    }
    if ($config['twofactor']['options']['withdraw']) {
      $popuptypes[] = 'withdrawals';
      $wf_editable = $user->token->isTokenValid($_SESSION['USERDATA']['id'], $oldtoken_wf, 7);
      $wf_sent = $user->token->doesTokenExist('withdraw_funds', $_SESSION['USERDATA']['id']);
    }
    $ptc = 0;
    $ptcn = count($popuptypes);
    foreach ($popuptypes as $pt) {
      if ($ptcn == 1) { $popupmsg.= $popuptypes[$ptc]; continue; }
      if ($ptc !== ($ptcn-1)) {
        $popupmsg.= $popuptypes[$ptc].', ';
      } else {
        $popupmsg.= 'and '.$popuptypes[$ptc];
      }
      $ptc++;
    }
    $_SESSION['POPUP'][] = array('CONTENT' => $popupmsg, 'TYPE' => 'info');
    // show our token status
    /*$ea_status = array($ea_sent, $ea_editable);   $statuses = array(0 => 'no', 1 => 'yes');
    $cp_status = array($cp_sent, $cp_editable);   $messages = array('Edit Account : ','Change Password : ', 'Withdraw Funds : ');
    $wf_status = array($wf_sent, $ewf_editable);   $alltokens = array($ea_status,$cp_status,$wf_status);
    $tokennum = 0; $tokenpopupmsg = "";
    foreach ($alltokens as $atoken) {
      $tokenpopupmsg = $message[$messages[$tokennum]]." ".$ea_status[0];
    }*/
  }
  
  if (isset($_POST['do']) && $_POST['do'] == 'genPin') {
    if (!$csrfenabled || $csrfenabled && $nocsrf) {
      if ($user->generatePin($_SESSION['USERDATA']['id'], $_POST['currentPassword'])) {
        $_SESSION['POPUP'][] = array('CONTENT' => 'Your PIN # has been sent to your email.', 'TYPE' => 'success');
      } else {
        $_SESSION['POPUP'][] = array('CONTENT' => $user->getError(), 'TYPE' => 'errormsg');
      }
    } else {
      $img = $csrftoken->getDescriptionImageHTML();
      $_SESSION['POPUP'][] = array('CONTENT' => "Page token expired, please try again $img", 'TYPE' => 'info');
    }
  }
  else {
    if ( @$_POST['do'] && (!$checkpin = $user->checkPin($_SESSION['USERDATA']['id'], @$_POST['authPin']))) {
      $_SESSION['POPUP'][] = array('CONTENT' => 'Invalid PIN. ' . ($config['maxfailed']['pin'] - $user->getUserPinFailed($_SESSION['USERDATA']['id'])) . ' attempts remaining.', 'TYPE' => 'errormsg');
    } else {
      if (isset($_POST['unlock']) && isset($_POST['utype']) && $checkpin) {
        $validtypes = array('account_edit','change_pw','withdraw_funds');
        $isvalid = in_array($_POST['utype'],$validtypes);
        if ($isvalid) {
          $ctype = strip_tags($_POST['utype']);
          if (!$csrfenabled || $csrfenabled && $nocsrf) {
            $send = $user->sendChangeConfigEmail($ctype, $_SESSION['USERDATA']['id']);
            if ($send) {
              $_SESSION['POPUP'][] = array('CONTENT' => 'A confirmation was sent to your e-mail, follow that link to continue', 'TYPE' => 'success');
            } else {
              $_SESSION['POPUP'][] = array('CONTENT' => $user->getError(), 'TYPE' => 'errormsg');
            }
          } else {
            $img = $csrftoken->getDescriptionImageHTML();
            $_SESSION['POPUP'][] = array('CONTENT' => "Page token expired, please try again $img", 'TYPE' => 'info');
          }
        }
      } else {
        switch (@$_POST['do']) {
          case 'cashOut':
        	if ($setting->getValue('disable_payouts') == 1 || $setting->getValue('disable_manual_payouts') == 1) {
        	  $_SESSION['POPUP'][] = array('CONTENT' => 'Manual payouts are disabled.', 'TYPE' => 'info');
        	} else {
        	  $aBalance = $transaction->getBalance($_SESSION['USERDATA']['id']);
        	  $dBalance = $aBalance['confirmed'];
        	  if ($dBalance > $config['txfee']) {
        	    if (!$oPayout->isPayoutActive($_SESSION['USERDATA']['id'])) {
        	      if (!$csrfenabled || $csrfenabled && $nocsrf) {
        	        if ($iPayoutId = $oPayout->createPayout($_SESSION['USERDATA']['id'], $wf_token)) {
        	          $_SESSION['POPUP'][] = array('CONTENT' => 'Created new manual payout request with ID #' . $iPayoutId);
        	        } else {
        	          $_SESSION['POPUP'][] = array('CONTENT' => $iPayoutId->getError(), 'TYPE' => 'errormsg');
        	        }
        	      } else {
        	        $img = $csrftoken->getDescriptionImageHTML();
        	        $_SESSION['POPUP'][] = array('CONTENT' => "Page token expired, please try again $img", 'TYPE' => 'info');
        	      }
        	    } else {
        	      $_SESSION['POPUP'][] = array('CONTENT' => 'You already have one active manual payout request.', 'TYPE' => 'errormsg');
        	    }
        	  } else {
        	    $_SESSION['POPUP'][] = array('CONTENT' => 'Insufficient funds, you need more than ' . $config['txfee'] . ' ' . $config['currency'] . ' to cover transaction fees', 'TYPE' => 'errormsg');
        	  }
        	}
        	break;
        
          case 'updateAccount':
            if (!$csrfenabled || $csrfenabled && $nocsrf) {
              if ($user->updateAccount($_SESSION['USERDATA']['id'], $_POST['paymentAddress'], $_POST['payoutThreshold'], $_POST['donatePercent'], $_POST['email'], $_POST['is_anonymous'], $ea_token)) {
            	$_SESSION['POPUP'][] = array('CONTENT' => 'Account details updated', 'TYPE' => 'success');
              } else {
            	$_SESSION['POPUP'][] = array('CONTENT' => 'Failed to update your account: ' . $user->getError(), 'TYPE' => 'errormsg');
              }
            } else {
              $img = $csrftoken->getDescriptionImageHTML();
              $_SESSION['POPUP'][] = array('CONTENT' => "Page token expired, please try again $img", 'TYPE' => 'info');
            }
        	break;
        
          case 'updatePassword':
            if (!$csrfenabled || $csrfenabled && $nocsrf) {
              if ($user->updatePassword($_SESSION['USERDATA']['id'], $_POST['currentPassword'], $_POST['newPassword'], $_POST['newPassword2'], $cp_token)) {
                $_SESSION['POPUP'][] = array('CONTENT' => 'Password updated', 'TYPE' => 'success');
              } else {
                $_SESSION['POPUP'][] = array('CONTENT' => $user->getError(), 'TYPE' => 'errormsg');
              }
            } else {
              $img = $csrftoken->getDescriptionImageHTML();
              $_SESSION['POPUP'][] = array('CONTENT' => "Page token expired, please try again $img", 'TYPE' => 'info');
            }
        	break;
        }
      }
    }
  }
}
// 2fa - one last time so we can sync with changes we made during this page
if ($user->isAuthenticated() && $config['twofactor']['enabled']) {
  // set the token to be the old token, just in case an error occured
  $ea_token = ($oldtoken_ea !== '') ? $oldtoken_ea : @$ea_token;
  $wf_token = ($oldtoken_wf !== '') ? $oldtoken_wf : @$wf_token;
  $cp_token = ($oldtoken_cp !== '') ? $oldtoken_cp : @$cp_token;
  if ($config['twofactor']['options']['details']) {
    $ea_editable = $user->token->isTokenValid($_SESSION['USERDATA']['id'], $ea_token, 5);
    $ea_sent = $user->token->doesTokenExist('account_edit', $_SESSION['USERDATA']['id']);
  }
  if ($config['twofactor']['options']['changepw']) {
    $cp_editable = $user->token->isTokenValid($_SESSION['USERDATA']['id'], $cp_token, 6);
    $cp_sent = $user->token->doesTokenExist('change_pw', $_SESSION['USERDATA']['id']);
  }
  if ($config['twofactor']['options']['withdraw']) {
    $wf_editable = $user->token->isTokenValid($_SESSION['USERDATA']['id'], $wf_token, 7);
    $wf_sent = $user->token->doesTokenExist('withdraw_funds', $_SESSION['USERDATA']['id']);
  }
}
// csrf stuff
$smarty->assign("CHANGEPASSUNLOCKED", $cp_editable);
$smarty->assign("WITHDRAWUNLOCKED", $wf_editable);
$smarty->assign("DETAILSUNLOCKED", $ea_editable);
$smarty->assign("CHANGEPASSSENT", $cp_sent);
$smarty->assign("WITHDRAWSENT", $wf_sent);
$smarty->assign("DETAILSSENT", $ea_sent);
if ($csrfenabled) {
  $token = $csrftoken->getBasic($user->getCurrentIP(), 'editaccount', 'mdyH');
  $smarty->assign('CTOKEN', $token);
}
// Tempalte specifics
$smarty->assign("CONTENT", "default.tpl");
?>
