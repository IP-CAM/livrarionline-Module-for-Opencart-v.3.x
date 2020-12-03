<?php
namespace LivrariOnline;

use sylouuu\Curl\Method as Curl;
use phpseclib\Crypt\AES as AES;
use phpseclib\Crypt\RSA as RSA;

class LO
{
    //private
    private $f_request    = null;
    private $f_secure    = null;
    private $aes_key    = null;
    private $iv        = null;
    private $rsa_key    = null;

    //definesc erorile standard: nu am putut comunica cu serverul, raspunsul de la server nu este de tip JSON. Restul de erori vin de la server
    private $error        = array('server' => 'Nu am putut comunica cu serverul', 'notJSON' => 'Raspunsul primit de la server nu este formatat corect (JSON)');
    private $conn        = null; //conexiunea la baza de date
    //public
    public $f_login    = null;
    public $version        = null;

    private $url_cancel_livrare            =  'https://api.livrarionline.ro/Lobackend.asmx/CancelLivrare';
    private $url_returnare_livrare         =  'https://api.livrarionline.ro/Lobackend.asmx/ReturnareLivrare';
    private $url_generare_awb              =  'https://api.livrarionline.ro/Lobackend.asmx/GenerateAwb';
    private $url_register_awb              =  'https://api.livrarionline.ro/Lobackend.asmx/RegisterAwb';
    private $url_tracking_awb              =  'https://api.livrarionline.ro/Lobackend.asmx/Tracking';
    private $url_estimare_pret             =  'https://estimare.livrarionline.ro/EstimarePret.asmx/EstimeazaPret';
    private $url_estimare_pret_servicii    =  'https://estimare.livrarionline.ro/EstimarePret.asmx/EstimeazaPretServicii';
    private $url_locker_expectedin         =  'https://smartlocker.livrarionline.ro/api/GetLockerExpectedInID';
    private $url_cancel_locker_expectedin  =  'https://smartlocker.livrarionline.ro/api/CancelLockerExpectedInID';
    private $url_get_locker_cell           =  'https://smartlocker.livrarionline.ro/api/GetLockerCellResevationID';

    //////////////////////////////////////////////////////////////
    // 						METODE PUBLICE						//
    //////////////////////////////////////////////////////////////

    //setez versiunea de kit
    public function __construct()
    {
        $this->version = "LO1.2";
        $this->iv = '285c02831e028bff';

        $this->conn = mysqli_connect(DB_HOSTNAME,DB_USERNAME,DB_PASSWORD,DB_DATABASE) or die('Could not connect to DATABASE');

        self::registerAutoload('phpseclib');
        self::registerAutoload('Curl');
    }

    //setez cheia RSA
    public function setRSAKey($rsa_key)
    {
        $this->rsa_key = $rsa_key;
    }

    //helper pentru validarea bifarii unui checkbox si trimiterea de valori boolean catre server
    public function checkboxSelected($value)
    {
        if ($value) {
            return true;
        }
        return false;
    }

    public function encrypt_ISSN($input)
    {
        $issn_key = substr($this->rsa_key, 0, 16) . substr($this->rsa_key, -16);

        $aes = new AES();
        $aes->setIV($this->iv);
        $aes->setKey($issn_key);

        $local_rez = ($aes->encrypt($input));

        return base64_encode($local_rez);
    }

    public function decrypt_ISSN($input)
    {
        $issn_key = substr($this->rsa_key, 0, 16).substr($this->rsa_key, -16);

        $aes = new AES();
        $aes->setIV($this->iv);
        $aes->setKey($issn_key);

        $issn = $aes->decrypt(base64_decode($input));

        return $issn;
    }

    //////////////////////////////////////////////////////////////
    // 				METODE COMUNICARE CU SERVER					//
    //////////////////////////////////////////////////////////////

    public function CancelLivrare($f_request)
    {
        return $this->LOCommunicate($f_request, $this->url_cancel_livrare);
    }

    public function ReturnareLivrare($f_request)
    {
        return $this->LOCommunicate($f_request, $this->url_returnare_livrare);
    }

    public function GenerateAwb($f_request)
    {
        return $this->LOCommunicate($f_request, $this->url_generare_awb);
    }

    public function GenerateAwbSmartloker($f_request, $delivery_point_id, $cellsize, $order_id)
    {
        // cellsize (1 -> L (440mm / 600mm / 611mm), 2 -> M (498mm / 600mm / 382mm), 3 -> S (498mm / 600mm / 300mm), 4 -> XL (600mm / 600mm / 600mm))
        $f_request['dulapid']         =  (int)$delivery_point_id;
        $f_request['tipid_celula']  =  (int) $cellsize; // obtinut prin call-ul de rezervare prin metoda get_reservationid
        $f_request['orderid']         =  strval($order_id);

        $sql    = "SELECT * FROM lo_delivery_points where dp_id = ".$delivery_point_id;
        $query    = mysqli_query($this->conn, $sql);
        $row    = mysqli_fetch_array($query);

        $f_request['shipTOaddress'] = array(                                                                            //Obligatoriu
            'address1'                =>  $row['dp_adresa'],
            'address2'                =>  '',
            'city'                    =>  $row['dp_oras'],
            'state'                =>  $row['dp_judet'],
            'zip'                    =>  $row['dp_cod_postal'],
            'country'                =>  $row['dp_tara'],
            'phone'                    =>  '',
            'observatii'            =>  ''
        );
        return $this->LOCommunicate($f_request, $this->url_generare_awb);
    }

    public function RegisterAwb($f_request)
    {
        return $this->LOCommunicate($f_request, $this->url_register_awb);
    }

    public function PrintAwb($f_request, $class)
    {
        return '<a class="'.$class.'" id="print-awb" href="http://api.livrarionline.ro/Lobackend_print/PrintAwb.aspx?f_login='.$this->f_login.'&awb='.$f_request['awb'].'" target="_blank">Click pentru print AWB</a>';
    }

    public function Tracking($f_request)
    {
        return $this->LOCommunicate($f_request, $this->url_tracking_awb);
    }

    public function EstimeazaPret($f_request)
    {
        return $this->LOCommunicate($f_request, $this->url_estimare_pret);
    }

    public function EstimeazaPretServicii($f_request)
    {
        return $this->LOCommunicate($f_request, $this->url_estimare_pret_servicii);
    }

    public function EstimeazaPretSmartlocker($f_request, $delivery_point_id, $order_id)
    {
        $f_request['dulapid']        =  (int)$delivery_point_id;
        $f_request['orderid']        =  strval($order_id);

        $sql    = "SELECT * FROM lo_delivery_points where dp_id = ".$delivery_point_id;
        $query    = mysqli_query($this->conn, $sql);
        $row    = mysqli_fetch_array($query);

        $f_request['shipTOaddress'] = array(                                                                            //Obligatoriu
            'address1'                =>  $row['dp_adresa'],
            'address2'                =>  '',
            'city'                    =>  $row['dp_oras'],
            'state'                =>  $row['dp_judet'],
            'zip'                    =>  $row['dp_cod_postal'],
            'country'                =>  $row['dp_tara'],
            'phone'                    =>  '',
            'observatii'            =>  ''
        );

        return $this->LOCommunicate($f_request, $this->url_estimare_pret);
    }

    public function getExpectedIn($f_request)
    {
        return $this->LOCommunicate($f_request, $this->url_locker_expectedin, true);
    }

    public function cancelExpectedIn($f_request)
    {
        return $this->LOCommunicate($f_request, $this->url_cancel_locker_expectedin, true);
    }

    public function get_sl_cell_reservation_id($f_request)
    {
        return $this->LOCommunicate($f_request, $this->url_get_locker_cell, true);
    }

    //////////////////////////////////////////////////////////////
    // 				END METODE COMUNICARE CU SERVER				//
    //////////////////////////////////////////////////////////////

    // CAUTARE PACHETOMATE DUPA LOCALITATE, JUDET SI DENUMIRE
    public function get_all_delivery_points($search)
    {
        $sql = "SELECT
			    dp.*,
				COALESCE(group_concat(
					CASE
						WHEN p.day_active = 0 and day_sort_order > 5 THEN CONCAT('<div>', p.day, ': <b>Inchis</b>')
						WHEN p.day_active = 1 and day_sort_order > 5 THEN CONCAT('<div>', p.`day`, ': <b>', DATE_FORMAT(p.dp_start_program,'%H:%i'), '</b> - <b>', DATE_FORMAT(p.dp_end_program,'%H:%i'),'</b>')
						WHEN p.day_active = 2 and day_sort_order > 5 THEN CONCAT('<div>', p.day, ': <b>Non-Stop</b>')
						WHEN p.day_active = 0 and day_sort_order = 5 THEN CONCAT('<div>Luni - ', p.day, ': <b>Inchis</b>')
						WHEN p.day_active = 1 and day_sort_order = 5 THEN CONCAT('<div>Luni - ', p.`day`, ': <b>', DATE_FORMAT(p.dp_start_program,'%H:%i'), '</b> - <b>', DATE_FORMAT(p.dp_end_program,'%H:%i'),'</b>')
						WHEN p.day_active = 2 and day_sort_order = 5 THEN CONCAT('<div>Luni - ', p.day, ': <b>Non-Stop</b>')
					END
					order by p.day_sort_order
					separator '</div>'
				),' - ') as orar
			FROM
				lo_delivery_points dp
					LEFT JOIN
				lo_dp_program p ON dp.dp_id = p.dp_id and day_sort_order > 4
			WHERE
				dp_active > 0
				AND (
					dp_judet like '%".$search."%'
					OR dp_oras like '%".$search."%'
					OR dp_denumire like '%".$search."%'
				)
			group by
				dp.dp_id
			order by
			    dp.dp_active desc, dp.dp_id asc
				";

        $delivery_points = array();

        $query = mysqli_query($this->conn, $sql);
        if (mysqli_num_rows($query) > 0) {
            while ($row = mysqli_fetch_array($query)) {
                $delivery_points[] = array(
                    'id'            => $row['dp_id'],
                    'denumire'        => $row['dp_denumire'],
                    'adresa'        => $row['dp_adresa'],
                    'judet'        => $row['dp_judet'],
                    'localitate'    => $row['dp_oras'],
                    'tara'            => $row['dp_tara'],
                    'cod_postal'    => $row['dp_cod_postal'],
                    'latitudine'    => $row['dp_gps_lat'],
                    'longitudine'    => $row['dp_gps_long'],
                    'tip'            => ($row['dp_tip']==1?'Pachetomat':'Punct de ridicare'),
                    'orar'            => $row['orar'],
                    'disabled'        => ((int)$row['dp_active']<=0?true:false)
                );
            }
        }
        return json_encode($delivery_points);
    }
    // END CAUTARE PACHETOMATE DUPA LOCALITATE, JUDET SI DENUMIRE

    // AFISARE INFORMATII DESPRE SMARTLOCKER (adresa, orar) dupa selectarea smartlocker-ului din lista de pachetomate disponibile
    public function get_delivery_point_by_id($delivery_point_id)
    {
        $sql = "SELECT
			    dp.*,
				COALESCE(group_concat(
					CASE
						WHEN p.day_active = 0 and day_sort_order > 5 THEN CONCAT('<div>', p.day, ': <b>Inchis</b>')
						WHEN p.day_active = 1 and day_sort_order > 5 THEN CONCAT('<div>', p.`day`, ': <b>', DATE_FORMAT(p.dp_start_program,'%H:%i'), '</b> - <b>', DATE_FORMAT(p.dp_end_program,'%H:%i'),'</b>')
						WHEN p.day_active = 2 and day_sort_order > 5 THEN CONCAT('<div>', p.day, ': <b>Non-Stop</b>')
						WHEN p.day_active = 0 and day_sort_order = 5 THEN CONCAT('<div>Luni - ', p.day, ': <b>Inchis</b>')
						WHEN p.day_active = 1 and day_sort_order = 5 THEN CONCAT('<div>Luni - ', p.`day`, ': <b>', DATE_FORMAT(p.dp_start_program,'%H:%i'), '</b> - <b>', DATE_FORMAT(p.dp_end_program,'%H:%i'),'</b>')
						WHEN p.day_active = 2 and day_sort_order = 5 THEN CONCAT('<div>Luni - ', p.day, ': <b>Non-Stop</b>')
					END
					order by p.day_sort_order
					separator '</div>'
				),' - ') as orar
			FROM
				lo_delivery_points dp
					LEFT JOIN
				lo_dp_program p ON dp.dp_id = p.dp_id and day_sort_order > 4
			WHERE
				dp.dp_id = ".$delivery_point_id."
			group by
				dp.dp_id
			order by
			    dp.dp_active desc, dp.dp_id asc
				";

        $delivery_point = array();

        $query = mysqli_query($this->conn, $sql);
        if (mysqli_num_rows($query) > 0) {
            $row = mysqli_fetch_array($query);
            $delivery_point = array(
                'id'            => $row['dp_id'],
                'denumire'        => $row['dp_denumire'],
                'adresa'        => $row['dp_adresa'],
                'judet'        => $row['dp_judet'],
                'localitate'    => $row['dp_oras'],
                'tara'            => $row['dp_tara'],
                'cod_postal'    => $row['dp_cod_postal'],
                'latitudine'    => $row['dp_gps_lat'],
                'longitudine'    => $row['dp_gps_long'],
                'tip'            => ($row['dp_tip']==1?'Pachetomat':'Punct de ridicare'),
                'orar'            => $row['orar'],
                'disabled'        => ((int)$row['dp_active']<=0?true:false)
            );
        }
        return json_encode($delivery_point);
    }
    // END AFISARE INFORMATII DESPRE SMARTLOCKER (adresa, orar) dupa selectarea smartlocker-ului din lista de pachetomate disponibile

    // METODA INCREMENTARE EXPECTEDIN
    public function plus_expectedin($delivery_point_id, $orderid)
    {
        $f_request_expected_in                  =  array();
        $f_request_expected_in['f_action']        =  3;
        $f_request_expected_in['f_orderid']    =  strval($orderid);
        $f_request_expected_in['f_lockerid']    =  $delivery_point_id;

        $this->getExpectedIn($f_request_expected_in);
    }
    // END METODA INCREMENTARE EXPECTEDIN

    // METODA SCADERE EXPECTEDIN
    public function minus_expectedin($delivery_point_id, $orderid)
    {
        $f_request_expected_in                  =  array();
        $f_request_expected_in['f_action']        =  8;
        $f_request_expected_in['f_orderid']    =  strval($orderid);
        $f_request_expected_in['f_lockerid']    =  $delivery_point_id;

        $this->cancelExpectedIn($f_request_expected_in);
    }
    // END METODA SCADERE EXPECTEDIN

    // GET RESERVATION ID
    public function get_reservationid($delivery_point_id, $cell_size = 3, $orderid)
    {
        $f_request = array();

        $f_request['f_action']         =  4;
        $f_request['f_lockerid']       =  $delivery_point_id;
        $f_request['f_marime_celula']  =  $cell_size; //1 -> L (440mm / 600mm / 611mm), 2 -> M (498mm / 600mm / 382mm), 3 -> S (498mm / 600mm / 300mm), 4 -> XL (600mm / 600mm / 600mm)
        $f_request['f_orders_id']      =  strval($orderid);

        $response = $this->get_sl_cell_reservation_id($f_request);

        if ($response->status == 'error') {
            $raspuns['status']                    = 'error';
            $raspuns['message']                = $response->message;
        } else {
            if ($response->error == 1) {
                if ($response->error_code == '01523') {
                    // eroare rezervare celula
                }
                $raspuns['status']                = 'error';
                $raspuns['error_code']            = $response->error_code;
                $raspuns['message']            = $response->error_message;
            } else {
                $raspuns['status']                = 'success';
                $raspuns['f_lockerid']            = $response->f_lockerid;
                $raspuns['f_reservation_id']    = $response->f_reservation_id;
            }
        }
        return json_encode($raspuns);
    }
    // END GET RESERVATION ID

    // ISSN
    public function issn()
    {
        $user_agent = strtolower($_SERVER['HTTP_USER_AGENT']);

        switch ($user_agent) {
            case "mozilla/5.0 (livrarionline.ro locker update service aes)":
                $this->run_lockers_update();
                break;

            case "mozilla/5.0 (livrarionline.ro issn service)":
                $this->run_issn();
                break;

            default:
                $this->run_issn();
                break;
        }
    }
    // END ISSN

    //////////////////////////////////////////////////////////////
    // 					END METODE PUBLICE						//
    //////////////////////////////////////////////////////////////

    //////////////////////////////////////////////////////////////
    // 						METODE PRIVATE						//
    //////////////////////////////////////////////////////////////

    private static function registerAutoload($classname)
    {
        spl_autoload_extensions('.php'); // Only Autoload PHP Files
        spl_autoload_register(function ($classname) {
            if (strpos($classname, '\\') !== false) {
                // Namespaced Classes
                $classfile = str_replace('\\', '/', $classname);
                if ($classname[0] !== '/') {
                    $classfile = dirname(__FILE__) . '/libraries/' . $classfile . '.php';
                }
                if (stripos($classname,'phpseclib') !== false || stripos($classname,'Curl') !== false) {
                    require($classfile);
                }
            }
        });
    }

    //criptez f_request cu AES
    private function AESEnc()
    {
        $this->aes_key = md5(uniqid());

        $aes = new AES();
        $aes->setIV($this->iv);
        $aes->setKey($this->aes_key);

        $this->f_request = bin2hex(base64_encode($aes->encrypt($this->f_request)));
    }

    //criptez cheia AES cu RSA
    private function RSAEnc()
    {
        $rsa = new RSA();
        $rsa->loadKey($this->rsa_key);
        $rsa->setEncryptionMode(RSA::ENCRYPTION_PKCS1);

        $this->f_secure = base64_encode($rsa->encrypt($this->aes_key));
    }

    //setez f_request, criptez f_request cu AES si cheia AES cu RSA
    private function setFRequest($f_request)
    {
        $this->f_request = json_encode($f_request);

        $this->AESEnc();
        $this->RSAEnc();
    }

    //construiesc JSON ce va fi trimis catre server
    private function createJSON($loapi = false)
    {
        $request               =  array();
        $request['f_login']    =  $this->f_login;
        $request['f_request']  =  $this->f_request;
        $request['f_secure']   =  $this->f_secure;

        if (!$loapi) {
            return json_encode(array('loapi' => $request));
        } else {
            return json_encode($request);
        }
    }

    //metoda pentru verificarea daca un string este JSON - folosit la primirea raspunsului de la server
    private function isJSON($string)
    {
        if (is_object(json_decode($string))) {
            return true;
        }
        return false;
    }

    //metoda pentru verificarea raspunsului obtinut de la server. O voi apela cand primesc raspunsul de la server
    private function processResponse($response, $loapi = false)
    {
        //daca nu primesc raspuns de la server
        if ($response == false) {
            return (object) array('status' => 'error','message' => $this->error['server']);
        } else {
            //verific daca raspunsul este de tip JSON
            if ($this->isJSON($response)) {
                $response = json_decode($response);
                if (!$loapi) {
                    return $response->loapi;
                } else {
                    return $response;
                }
            } else {
                return (object) array('status' => 'error','message' => $this->error['notJSON']);
            }
        }
    }

    //metoda comunicare cu server LO
    private function LOCommunicate($f_request, $urltopost, $loapi = false)
    {
        $this->setFRequest($f_request);

        $cc = new Curl\Post(
            $urltopost,
            array(
                    'data' => array('loapijson' => $this->createJSON($loapi))
                )
            );
        $response = $cc->send();

        return $this->processResponse($response->getResponse(), $loapi);
    }

    // SMARTLOCKER UPDATE
    private function run_lockers_update()
    {
        $posted_json = file_get_contents('php://input');

        $lockers_data = $this->decrypt_ISSN($posted_json);

        if (empty($lockers_data)) {
            throw new \Exception('No data sent for Smartlocker update');
        }

        $lockers_data = json_decode($lockers_data, true);

        $error = false;

        $login_id            = $lockers_data['merchid'];
        $lo_delivery_points = $lockers_data['dulap'];
        $lo_dp_program        = $lockers_data['zile2dulap'];
        $lo_dp_exceptii    = $lockers_data['exceptii_zile'];

        foreach ($lo_delivery_points as $delivery_point) {
            $check = mysqli_fetch_array(mysqli_query($this->conn, "SELECT count(dp_id) AS `exists` FROM `lo_delivery_points` WHERE dp_id = ".(int)$delivery_point['dulapid'].";"));
            if ((int)$check['exists'] < 1) {
                $sql = "INSERT INTO `lo_delivery_points`
							(`dp_id`,
							`dp_denumire`,
							`dp_adresa`,
							`dp_judet`,
							`dp_oras`,
							`dp_tara`,
							`dp_gps_lat`,
							`dp_gps_long`,
							`dp_tip`,
							`dp_active`,
							`version_id`,
							`dp_temperatura`,
							`dp_indicatii`,
							`termosensibil`)
						VALUES
							(".(int)$delivery_point['dulapid'].",
							'".$delivery_point['denumire']."',
							'".$delivery_point['adresa']."',
							'".$delivery_point['judet']."',
							'".$delivery_point['oras']."',
							'".$delivery_point['tara']."',
							".(float)$delivery_point['latitudine'].",
							".(float)$delivery_point['longitudine'].",
							".(int)$delivery_point['tip_dulap'].",
							".(int)$delivery_point['active'].",
							".(int)$delivery_point['versionid'].",
							".(float)$delivery_point['dp_temperatura'].",
							'".$delivery_point['dp_indicatii']."',
							".(int)$delivery_point['termosensibil'].")
				";
            } else {
                $sql = "UPDATE
							`lo_delivery_points`
						SET
							`dp_denumire` = '".$delivery_point['denumire']."',
							`dp_adresa` = '".$delivery_point['adresa']."',
							`dp_judet` = '".$delivery_point['judet']."',
							`dp_oras` = '".$delivery_point['oras']."',
							`dp_tara` = '".$delivery_point['tara']."',
							`dp_gps_lat` = ".(float)$delivery_point['latitudine'].",
							`dp_gps_long` = ".(float)$delivery_point['longitudine'].",
							`dp_tip` = ".(int)$delivery_point['tip_dulap'].",
							`dp_active` = ".(int)$delivery_point['active'].",
							`version_id` = ".(int)$delivery_point['versionid'].",
							`dp_temperatura` = ".(float)$delivery_point['dp_temperatura'].",
							`dp_indicatii` = '".$delivery_point['dp_indicatii']."',
							`termosensibil` = ".(float)$delivery_point['termosensibil']."
						WHERE
							`dp_id` = ".(int)$delivery_point['dulapid']."
				";
            }
            $query = mysqli_query($this->conn, $sql);
        }

        foreach ($lo_dp_program as $program) {
            $check = mysqli_fetch_array(mysqli_query($this->conn, "SELECT count(leg_id) AS `exists` FROM `lo_dp_program` WHERE dp_id = ".(int)$program['dulapid']." AND day_number = ".(int)$program['day_number'].";"));
            if ((int) $check['exists'] < 1) {
                $sql = "INSERT INTO `lo_dp_program`
							(`dp_start_program`,
							`dp_end_program`,
							`dp_id`,
							`day_active`,
							`version_id`,
							`day_number`,
							`day`)
						VALUES
							('".$program['start_program']."',
							'".$program['end_program']."',
							".(int)$program['dulapid'].",
							".(int)$program['active'].",
							".(int)$program['versionid'].",
							".(int)$program['day_number'].",
							'".$program['day_name']."')
				";
            } else {
                $sql = "UPDATE
							`lo_dp_program`
						SET
							`dp_start_program` = '".$program['start_program']."',
							`dp_end_program` = '".$program['end_program']."',
							`day_active` = ".(int)$program['active'].",
							`version_id` = ".(int)$program['versionid'].",
							`day` = '".$program['day_name']."'
						WHERE
							dp_id = ".(int)$program['dulapid']."
							and day_number = ".(int)$program['day_number']."
				";
            }
            $query = mysqli_query($this->conn, $sql);
        }

        foreach ($lo_dp_exceptii as $exceptie) {
            $check = mysqli_fetch_array(mysqli_query($this->conn, "SELECT count(leg_id) AS `exists` FROM `lo_dp_day_exceptions` WHERE dp_id = ".(int)$exceptie['dulapid']." AND date(exception_day) = date('".$exceptie['ziua']."');"));
            if ((int) $check['exists'] < 1) {
                $sql = "INSERT INTO `lo_dp_day_exceptions`
							(`dp_start_program`,
							`dp_end_program`,
							`dp_id`,
							`active`,
							`version_id`,
							`exception_day`)
						VALUES
							('".$exceptie['start_program']."',
							'".$exceptie['end_program']."',
							".(int)$exceptie['dulapid'].",
							".(int)$exceptie['active'].",
							".(int)$exceptie['versionid'].",
							'".$exceptie['ziua']."')
				";
            } else {
                $sql = "UPDATE
							`lo_dp_day_exceptions`
						SET
							`dp_start_program` = '".$exceptie['start_program']."',
							`dp_end_program` = '".$exceptie['end_program']."',
							`active` = ".(int)$exceptie['active'].",
							`version_id` = ".(int)$exceptie['versionid']."
						WHERE
							dp_id = ".(int)$exceptie['dulapid']."
							and date(exception_day) = date('".$exceptie['ziua']."')
				";
            }
            $query = mysqli_query($this->conn, $sql);
        }

        $sql = "SELECT
					COALESCE(MAX(dp.version_id), 0) AS max_dulap_id,
					COALESCE(MAX(dpp.version_id), 0) AS max_zile2dp,
				    COALESCE(MAX(dpe.version_id), 0) AS max_exceptii_zile
				FROM
					lo_delivery_points dp
					LEFT join lo_dp_program dpp ON dpp.dp_id = dp.dp_id
					LEFT join lo_dp_day_exceptions dpe ON dpe.dp_id = dp.dp_id";
        $query    = mysqli_query($this->conn, $sql);
        $row    = mysqli_fetch_array($query);

        $response['merch_id']           =  (int) $login_id;
        $response['max_dulap_id']       =  (int) $row['max_dulap_id'];
        $response['max_zile2dp']        =  (int) $row['max_zile2dp'];
        $response['max_exceptii_zile']  =  (int) $row['max_exceptii_zile'];

        echo json_encode($response);
    }
    // END SMARTLOCKER UPDATE


    // ISSN UPDATE ORDER STATUS
    private function run_issn()
    {
        if (!isset($_POST) || !isset($_POST['F_CRYPT_MESSAGE_ISSN']) || !$_POST['F_CRYPT_MESSAGE_ISSN']) {
            throw new \Exception('F_CRYPT_MESSAGE_ISSN nu a fost trimis');
        }
        $F_CRYPT_MESSAGE_ISSN = $_POST['F_CRYPT_MESSAGE_ISSN'];
        $error = false;
        $issn = $this->decrypt_ISSN($F_CRYPT_MESSAGE_ISSN); //obiect decodat din JSON in clasa LO
        if (!isset($issn) || empty($issn)) {
            throw new \Exception('Hacking Attempt');
        }
        //issn este un obiect, cu atributele: f_order_number, f_statusid, f_stamp, f_awb_collection (array de AWB-uri)

        //f_order_number - referinta
        if (isset($issn->f_order_number)) {
            $vF_Ref = $issn->f_order_number;
        } else {
            $error = true;
            throw new \Exception('Parametrul f_order_number lipseste.');
        }
        //f_statusid
        if (isset($issn->f_statusid)) {
            $vF_statusid = $issn->f_statusid;
        } else {
            $error = true;
            throw new \Exception('Parametrul f_statusid lipseste.');
        }
        // f_stamp
        if (isset($issn->f_stamp)) {
            $vF_stamp = $issn->f_stamp;
        } else {
            $error = true;
            throw new \Exception('Parametrul f_stamp lipseste.');
        }
        // f_awb_collection
        if (isset($issn->f_awb_collection)) {
            $vF_AWB = $issn->f_awb_collection; //array de awb-uri
            $vF_AWB = $vF_AWB[0];
        } else {
            $error = true;
            throw new \Exception('Parametrul f_awb lipseste.');
        }
        // Obtine order id
        $raw_vF_Order_Number = explode('nr.', $vF_Ref);
        $vF_Order_Number = trim($raw_vF_Order_Number[1]);

        if (!$error) {
            switch ($vF_statusid) {
                case '100':
                    // Preluata de curier de la comerciant, de actualizat starea comenzii in sistem
                    break;
                case '110':
                    // Preluata de curier din Smart Locker, de actualizat starea comenzii in sistem
                    break;
                case '130':
                    // Predata in hub, de actualizat starea comenzii in sistem
                    break;
                case '150':
                    // Preluata de curier din hub, de actualizat starea comenzii in sistem
                    break;
                case '290':
                    // Predata in Smart Locker, de actualizat starea comenzii in sistem
                    break;
                case '300':
                    // Livrata la destinatar, de actualizat starea comenzii in sistem
                    break;
                case '600':
                    // Anulata, de actualizat starea comenzii in sistem
                    break;
                case '900':
                    // Facturata, de actualizat starea comenzii in sistem
                    break;
                case '1000':
                    // Finalizata, de actualizat starea comenzii in sistem
                    break;

            }
            $stare1='<f_response_code>0</f_response_code>';
            $raspuns_xml = '<?xml version="1.0" encoding="UTF-8" ?>';
            $raspuns_xml .= '<issn>';
            $raspuns_xml .= '<x_order_number>'.$issn->f_order_number.'</x_order_number>';
            $raspuns_xml .= '<merchServerStamp>'.date("Y-m-dTH:m:s").'</merchServerStamp>';
            $raspuns_xml .= '<f_response_code>1</f_response_code>';
            $raspuns_xml .= '</issn>';
            echo $raspuns_xml;
        }
    }
    // END ISSN UPDATE ORDER STATUS

    //////////////////////////////////////////////////////////////
    // 						END METODE PRIVATE					//
    //////////////////////////////////////////////////////////////
}
?>