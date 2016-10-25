<?php
/**
 * The T411 class is used to make API calls to the tracker
 *
 * @author Matthias BOSC <matthias@bosc.io>
 */
class T411 {

  const API_URL = 'https://api.t411.ch';
  const WEB_URL = 'https://t411.ch';
  /** MySQL */
  const DB_HOST = 'localhost';
  const DB_NAME = 't412';
  const DB_USER = '';
  const DB_PASS = '';
  /** clé de sécurité */
  const KEY = "";
  /** préfixe pour DL Syno -- WIP */
  const DL_PREFIX = '';
  /** nom de domaine */
  public $domainName = 'domain.tld';
  /** utilisateur t411 - pour lancer les requêtes cron */
  CONST T411USER = '';

  /** variable de classe */
  public $token = null;
  public $uid;
  public $url;
  public $sort_order = 3;
  public $order;
  public $id;
  public $comments;
  public $hash;
  public $nfo;

  /**
   * Constructeur de la classe
   * Lorsque la classe est instanciée, on vérifie la présence du cookie token
   * *Sauf* si le fichier est lancé en cli ou user pas encore connecté
   * Si tout est ok, on cast le token et l'uid dans un objet
   *
   * @param bool $connected
   */
  function __construct($connected = true) {
    if($connected) {
      $this->token = isset($_COOKIE['token']) ? $_COOKIE['token'] : $this->login();
      $this->uid = isset($_COOKIE['token']) ? $this->getUid($this->token) : null;
      isset($_COOKIE['token']) && !isset($_COOKIE['username']) ? $this->genDetailsCookie() : null;
    }
  }

  /**
   * Lors de chaque requête à la BDD utilisant l'uid comme identifiant
   * Je fais une requête vers l'API T411 en utilisant le token de l'utilisateur
   * Ceci dans le but de valider le token, et ainsi éviter que quelqu'un
   * Modifiant son uid intentionnellement puisse récupérer les données d'autrui
   */
  function tokenIsValid($token = null) {
    $ch = curl_init(self::API_URL . '/users/profile/' . $this->uid);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: ' . $this->token]);
    $result = curl_exec($ch);
    //$this->userinfo = json_decode($result);
    return array_keys_exists('success', json_decode($result)) ? true : false;
  }

  /**
   * Supprime la clé "torrents" de certains retours d'API
   * Cela facilite grandement la manipulation des arrays par la suite
   *
   * @param array $array l'array json_décodé qu'on obtiens en réponse d'une requête
   * @return array
   */
  public function standarsize($array) {
    return array_key_exists('torrents', $array) ? $array->torrents : $array;
  }

  /**
   * Supprime les charactères spéciaux des noms de séries
   * pour pouvoir les évaluer correctement
   *
   * @param string name
   * @return string
   */
  public function cleanTitle($name) {
    return strtr(str_replace(': ', '', $name), '.:-_+ ', '......');
  }


  /**
   * Supprime les valeurs null d'un tableau
   * L'API ajoute parfois une valeur nulle lorsque un torrent n'est pas validé
   * Cela fait foirer le tri avec array_multisort()
   * array_values s'occupe de réindexer les clés avant de les retourner
   *
   * @param array $array
   * @return array
   */
  public function cleanArray($array) {
    foreach ($array as $key => $value) {
      if(!is_object($value)) { unset($array[$key]); }
    }
    return array_values($array);
  }


  /**
   * Tri un tableau avec une clé spécifiée
   *
   * @param array $array
   * @return array
   */
  public function sortArray($array) {
    array_multisort(array_column($array, $this->order), $this->sort_order, SORT_FLAG_CASE | SORT_NATURAL, $array);
    return $array;
  }

  /**
   * Retourne le langage humainement lisible d'une série
   * @param int $code
   * @return string
   */
  public function getLanguage($code) {
    switch ($code) {
      case 1209:
        $message = 'VO';
        break;
      case 1210:
        $message = 'VFF';
        break;
      case 1212:
        $message = 'Multi VO/VFF';
        break;
      case 1216:
        $message = 'VOST/FR';
        break;
      default:
        $message = 'Langue';
        break;
    }
    return $message;
  }

  /**
   * Génère 3 cookies contenant username, downloaded et uploaded
   * Ils sont utilisés par le front-end pour afficher pseudo et ratio
   * Les cookies ont une durée de vie de 2h pour éviter le flood sur l'API
   */
  private function genDetailsCookie() {
    $this->getUserInfo();
    setcookie('username', $this->userinfo->username, time()+7200);
    setcookie('downloaded', $this->formatBytes($this->userinfo->downloaded), time()+7200);
    setcookie('uploaded', $this->formatBytes($this->userinfo->uploaded), time()+7200);
    header('Refresh: 0');
    exit;
  }

  /**
   * Scrape les données d'un code HTML donné ($data)
   * Retourne le code contenu entre $start et $end
   *
   * @param string $data
   * @param $string start
   * @param $string end
   * @return string
   */
  public function scrape($data, $start, $end) {
    $data = stristr($data, $start);
    $data = substr($data, strlen($start));
    $stop = stripos($data, $end);
    $data = substr($data, 0, $stop);
    return $data;
  }

  /**
   * Utilise la fonction scrape() pour récupérer les commentaires
   * Retourne le résultat dans un tableau
   */
  public function loadComments() {
    $reponse = $this->scrape($this->web, '<table class="comment" width="100%">','</table>');
    $raw = explode('<th width="120px"', $reponse);
    unset($raw[0]);

    foreach ($raw as $value) {
      $date = explode('>', $this->scrape($value, '/users/comment/?id=', '</a>'));
      $comments[] = (object)['pseudo' => $this->scrape($value, 'title="', '"'), "date" => date_format(date_create($date[1]), 'd/m/Y'), 'texte' => $this->scrape($value, '<p>', '</p>')];
    }
    $this->comments = !empty($comments) ? $comments : null;
  }

  /**
   * Utilise la fonction scrape() pour récupérer le hash d'un torrent
   * Utilisé pour savoir si il a déjà été téléchargé
   */
  public function loadHash() {
    $hash = $this->scrape($this->web, '<th>Info Hash</th>','</tr>');
    $hash = $this->scrape($hash, '<td>','</td>');

    $this->hash = !empty($hash) ? $hash : null;
  }

  /**
   * Scrape la page du NFO du torrent et récupère son contenu
   */
  public function loadNFO() {
    $this->nfo = $this->scrape($this->nfo, '<pre>','</pre>');
  }

}
