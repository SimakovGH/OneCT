<?php 
    ini_set('display_errors', false); // Скрываем рукожопость автора

    require_once "../include/config.php";

    use Otp\Otp;
    use Otp\GoogleAuthenticator;
    use ParagonIE\ConstantTime\Encoding;

    class account{
        function login($email, $pass, $code){
            global $db;
            $response = array();
            
            $query = $db->query("SELECT token, pass, id, ban, secret FROM users WHERE email = " .$db->quote($email));
            $info = $query->fetch();

            // Ты точно есть?

            if($query->rowCount() == 0){
                $response = array('error' => 'Bad login or password');
            }

            // Ты наш или лживый говнюк?

            if(!password_verify($pass, $info['pass'])){
                $response = array('error' => 'Bad login or password');
            }

            if($info['ban'] == 1){
                $db->query("UPDATE users SET token='' WHERE email=" .$db->quote($_REQUEST['email']));
                $response = array('error' => 'Your account has been banned');
            }

            if($info['secret'] != NULL){
                require_once "../vendor/autoload.php";

                $otp = new Otp();

                if($code == NULL){
                    $code = '1';
                }

                if($otp->checkTotp(Encoding::base32DecodeUpper($info['secret']), $code)) {
                    
                } else{
                    $response = array('error' => 'Bad 2fa code');
                }
            }

            // Проходи

            if($response['error'] == null){
                $response = array(
                    'token' => $info['token'],
                    'user_id' => $info['id']
                );

                // Нету токена?
                
                if($info['token'] == null){
                    $permitted_chars = '0123456789abcdefghijklmnopqrstuvwxyz';

                    $generated_token = 'onect-'.substr(str_shuffle($permitted_chars), 0, 13).
                    '-'.substr(str_shuffle($permitted_chars), 0, 13).
                    '-'.substr(str_shuffle($permitted_chars), 0, 13).
                    '-'.substr(str_shuffle($permitted_chars), 0, 13);

				    $db->query("UPDATE users SET token='" .$generated_token. "' WHERE email=" .$db->quote($_REQUEST['email']));

                    $response['token'] = $generated_token;
                }
            }

            return $response;
        }

        function check($token){
            global $db;
            $response = array();
            
            $get_user_token = $db->query("SELECT * FROM users WHERE token = " .$db->quote($token));
            $user_data = $get_user_token->fetch();

            if(!empty(trim($token)) or $token != null){
                if($user_data['ban'] == 1){
                    $db->query("UPDATE users SET token='' WHERE token=" .$db->quote($token));
                    $get_user_token = $db->query("SELECT * FROM users WHERE token = " .$db->quote($token));
                }

                if($get_user_token->rowCount() == 0){
                    // Сам знаешь

                    $response = array(
                        'account_login' => 0
                    );
                } else {
                    $response = array(
                        'account_login' => 1,
                        'username' => $user_data['name']
                    );

                    // Проверка 2fa
                    if($user_data['secret'] != NULL){
                        $response['2fa'] = 1;
                    } else{
                        $response['2fa'] = 0;
                    }
                }
            } else{
                $response = array(
                    'error' => 'Bad token'
                );
            }

            return $response;
        }
    }

    if(isset($_REQUEST['method'])){
        header('Content-Type: application/json');

        $account = new account();

        switch($_REQUEST['method']){
            case 'login':
                echo json_encode($account->login($_REQUEST['email'], $_REQUEST['pass'], $_REQUEST['code']));
                break;
            case 'check':
                echo json_encode($account->check($_REQUEST['token']));
                break;
            default:
                http_response_code(400);
                echo json_encode(array('error' => 'Invalid method'));
                break;
        }

        $db = null;
    }

    