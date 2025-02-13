<?php
/*
Gibbon, Flexible & Open School System
Copyright (C) 2010, Ross Parker

This program is free software: you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation, either version 3 of the License, or
(at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program. If not, see <http://www.gnu.org/licenses/>.
*/

use Gibbon\Data\Validator;
use Gibbon\Contracts\Comms\Mailer;

include './gibbon.php';

//Create password
$password = randomPassword(8);

// Sanitize the $_GET and $_POST arrays
$validator = $container->get(Validator::class);
$_GET = $validator->sanitize($_GET);
$_POST = $validator->sanitize($_POST);

//Check email address is not blank
$input = $_GET['input'] ?? ($_POST['email'] ?? '');
$step = $_GET['step'];

$URL = $session->get('absoluteURL').'/index.php?q=passwordReset.php';
$URLSuccess1 = $session->get('absoluteURL').'/index.php';

if ($input == '' or ($step != 1 and $step != 2)) {
    $URL = $URL.'&return=error0';
    header("Location: {$URL}");
}
//Otherwise proceed
else {
    try {
        $data = array('email' => $input, 'username' => $input);
        $sql = "SELECT gibbonPersonID, email, username, canLogin, gibbonRoleIDPrimary FROM gibbonPerson WHERE (email=:email OR username=:username) AND gibbonPerson.status='Full' AND NOT email=''";
        $result = $connection2->prepare($sql);
        $result->execute($data);
    } catch (PDOException $e) {
        $URL = $URL.'&return=error2';
        header("Location: {$URL}");
        exit();
    }

    if ($result->rowCount() != 1) {
        $URL = $URL.'&return=error4';
        header("Location: {$URL}");
    } else {
        $row = $result->fetch();

        // Insufficient privileges to login
        if ($row['canLogin'] != 'Y') {
            $URL .= '&return=fail2';
            header("Location: {$URL}");
            exit;
        }

        // Get primary role info
        $data = array('gibbonRoleIDPrimary' => $row['gibbonRoleIDPrimary']);
        $sql = "SELECT * FROM gibbonRole WHERE gibbonRoleID=:gibbonRoleIDPrimary";
        $role = $pdo->selectOne($sql, $data);

        // Login not allowed for this role
        if (!empty($role['canLoginRole']) && $role['canLoginRole'] != 'Y') {
            $URL .= '&return=fail9';
            header("Location: {$URL}");
            exit;
        }

        $gibbonPersonID = $row['gibbonPersonID'];
        $email = $row['email'];
        $username = $row['username'];

        if ($step == 1) { //This is the request phase
            //Generate key
            $key = randomPassword(40);

            //Try to delete other recors for this user
            
                $data = array('gibbonPersonID' => $gibbonPersonID);
                $sql = "DELETE FROM gibbonPersonReset WHERE gibbonPersonID=:gibbonPersonID";
                $result = $connection2->prepare($sql);
                $result->execute($data);

            //Insert key record
            try {
                $data = array('gibbonPersonID' => $gibbonPersonID, 'key' => $key);
                $sql = "INSERT INTO gibbonPersonReset SET gibbonPersonID=:gibbonPersonID, `key`=:key";
                $result = $connection2->prepare($sql);
                $result->execute($data);
            } catch (PDOException $e) {
                $URL = $URL.'&return=error2';
                header("Location: {$URL}");
                exit();
            }
            $gibbonPersonResetID = str_pad($connection2->lastInsertID(), 12, '0', STR_PAD_LEFT);

            //Send email
            $subject = $session->get('organisationNameShort').' '.__('Gibbon Password Reset');
            $body = sprintf(__('A password reset request has been initiated for account %1$s, which is registered to this email address.%2$sIf you did not initiate this request, please ignore this email.%2$sIf you do wish to reset your password, please use the link below to access the reset form:%2$s%3$s%2$s%4$s'), $username, "\n\n", '', '');

            $mail = $container->get(Mailer::class);
            $mail->AddAddress($email);

            if ($session->exists('organisationEmail') && $session->get('organisationEmail') != '') {
                $mail->SetFrom($session->get('organisationEmail'), $session->get('organisationName'));
            } else {
                $mail->SetFrom($session->get('organisationAdministratorEmail'), $session->get('organisationName'));
            }

            $mail->Subject = $subject;
            $mail->renderBody('mail/email.twig.html', [
                'title'  => __('Password Reset'),
                'body'   => nl2br(trim($body, "\n")),
                'button' => [
                    'url'  => "/index.php?q=/passwordReset.php&input=$input&step=2&gibbonPersonResetID=$gibbonPersonResetID&key=$key",
                    'text' => __('Click Here'),
                ],
            ]);

            if ($mail->Send()) {
                $URL = $URL.'&return=success0';
                header("Location: {$URL}");
            } else {
                $URL = $URL.'&return=error3';
                header("Location: {$URL}");
            }
        }
        else { //This is the confirmation/reset phase
            //Get URL parameters
        	$input = $_GET['input'];
        	$key = $_GET['key'];
        	$gibbonPersonResetID = $_GET['gibbonPersonResetID'];

        	//Verify authenticity of this request and check it is fresh (within 48 hours)
        	try {
                $data = array('key' => $key, 'gibbonPersonResetID' => $gibbonPersonResetID);
                $sql = "SELECT * FROM gibbonPersonReset WHERE `key`=:key AND gibbonPersonResetID=:gibbonPersonResetID AND (timestamp > DATE_SUB(now(), INTERVAL 2 DAY))";
                $result = $connection2->prepare($sql);
                $result->execute($data);
            } catch (PDOException $e) {
                $URL = $URL.'&return=error2';
                header("Location: {$URL}");
                exit();
            }

        	if ($result->rowCount() != 1) {
                $URL = $URL.'&return=error2';
                header("Location: {$URL}");
        	} else {
                $row = $result->fetch();
                $gibbonPersonID = $row['gibbonPersonID'];
                $passwordNew = $_POST['passwordNew'];
                $passwordConfirm = $_POST['passwordConfirm'];

                //Check passwords are not blank
                if ($passwordNew == '' or $passwordConfirm == '') {
                    $URL .= '&return=error1';
                    header("Location: {$URL}");
                } else {
                    //Check that new password is not same as old password
                    if ($password == $passwordNew) {
                        $URL .= '&return=error7';
                        header("Location: {$URL}");
                    } else {
                        //Check strength of password
                        $passwordMatch = doesPasswordMatchPolicy($connection2, $passwordNew);

                        if ($passwordMatch == false) {
                            $URL .= '&return=error6';
                            header("Location: {$URL}");
                        } else {
                            //Check new passwords match
                            if ($passwordNew != $passwordConfirm) {
                                $URL .= '&return=error5';
                                header("Location: {$URL}");
                            } else {
                                //Update password
                                $salt = getSalt();
                                $passwordStrong = hash('sha256', $salt.$passwordNew);
                                try {
                                    $data = array('passwordStrong' => $passwordStrong, 'salt' => $salt, 'gibbonPersonID' => $gibbonPersonID);
                                    $sql = "UPDATE gibbonPerson SET password='', passwordStrong=:passwordStrong, passwordStrongSalt=:salt, passwordForceReset='N', failCount=0 WHERE gibbonPersonID=:gibbonPersonID";
                                    $result = $connection2->prepare($sql);
                                    $result->execute($data);
                                } catch (PDOException $e) {
                                    $URL .= '&return=error2';
                                    header("Location: {$URL}");
                                    exit();
                                }

                                //Remove requests for this person
                                
                                    $data = array('gibbonPersonID' => $gibbonPersonID);
                                    $sql = "DELETE FROM gibbonPersonReset WHERE gibbonPersonID=:gibbonPersonID";
                                    $result = $connection2->prepare($sql);
                                    $result->execute($data);

                                //Return
                                $URL = $URLSuccess1.'?return=success1';
                                header("Location: {$URL}");
                            }
                        }
                    }
                }
            }
        }
    }
}
