<?php

use ISPServerfarm\UnifiMeshviewer\MeshviewerGenerator;
use Dotenv\Dotenv;

// load the class using the composer autoloader
require_once('../vendor/autoload.php');

// load Whoops
if (class_exists("\Whoops\Run")){
    $whoops = new \Whoops\Run;
    $whoops->prependHandler(new \Whoops\Handler\PrettyPageHandler);
    $whoops->register();
}

// set Content Type to application/json
header('Content-Type: application/json');

if(file_exists("../unifi.json")){
    $unifi =  json_decode(file_get_contents("../unifi.json"),true);

    $ret_status = array();
    $ar_nodelist = array();
    $ar_meshviewer = array();

    foreach ($unifi['controller'] as $env) {

        if(file_exists('../.env.'.$env)) {

            // load dotenv and the .env file.
            $dotenv = Dotenv::create(dirname(dirname(__FILE__)), '.env.'.$env);
            $dotenv->overload(true);

            // set TimeZone
            date_default_timezone_set(getenv('TIMEZONE'));


            // check if the controller is accessible
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, getenv('UNIFI_URL'));
            curl_setopt($ch, CURLOPT_HEADER, TRUE);
            curl_setopt($ch, CURLOPT_NOBODY, TRUE);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, FALSE);
            curl_setopt($ch, CURLOPT_TIMEOUT, 5);
            curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);     
        
            if(curl_errno($ch)) {
                $ret_status[$env] = array('cURL-Error'=>curl_error($ch),'URL'=>getenv('UNIFI_URL'));
            }
            else {
                // Initiate the MeshviewerGenerator
                $meshGenerator = new MeshviewerGenerator();

                $ret_status[$env] = json_decode($meshGenerator->executeTask());

                if(file_exists("data/nodelist.json")) {
                    
                    if(!is_dir("data/$env/")) mkdir("data/$env/", 0700);
                    if(file_exists("data/nodes.json")) rename("data/nodes.json", "data/$env/nodes.json");
                    if(file_exists("data/graph.json")) rename("data/graph.json", "data/$env/graph.json");
                    if(file_exists("data/nodelist.json")) rename("data/nodelist.json", "data/$env/nodelist.json");
                    if(file_exists("data/meshviewer.json")) rename("data/meshviewer.json", "data/$env/meshviewer.json");

                    if(file_exists("data/$env/nodelist.json")) $ar_nodelist[] = json_decode(file_get_contents("data/$env/nodelist.json"), true);
                    if(file_exists("data/$env/meshviewer.json")) $ar_meshviewer[] = json_decode(file_get_contents("data/$env/meshviewer.json"), true);

                }
            }
            curl_close($ch);
        }
    }

    if(count($ar_nodelist)>0) {

        $ret_array = array();

        foreach ($ar_nodelist as $value) {
            $ret_array = array_merge_recursive($ret_array, $value);
        }
        
        file_put_contents("data/nodelist.json", json_encode($ret_array));
    }

    if(count($ar_meshviewer)>0) {

        $ret_array = array();

        foreach ($ar_meshviewer as $value) {
            $ret_array = array_merge_recursive($ret_array, $value);
        }
        
        file_put_contents("data/meshviewer.json", json_encode($ret_array));
    }

    echo json_encode($ret_status,JSON_PRETTY_PRINT);

}
else {

    // load dotenv and the .env file.
    $dotenv = Dotenv::create(dirname(dirname(__FILE__)));
    $dotenv->load(true);

    // set TimeZone
    date_default_timezone_set(getenv('TIMEZONE'));    

    // Initiate the MeshviewerGenerator
    $meshGenerator = new MeshviewerGenerator();

    // Generate the JSON Files
    $status = $meshGenerator->executeTask();

    echo $status;
}

?>