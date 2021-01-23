<?php if ( ! defined('BASEPATH')) exit('No direct script access allowed');
require dirname(__FILE__) . '/TCPDF/tcpdf.php';
class Pdf extends TCPDF
{
    function __construct()
    {
        parent::__construct();
    }
}