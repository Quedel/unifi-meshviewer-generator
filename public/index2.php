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

    foreach ($unifi['controller'] as $env) {

        if(file_exists('../.env.'.$env)) {

            // load dotenv and the .env file.
            $dotenv = Dotenv::create(dirname(dirname(__FILE__)), '.env.'.$env);
            $dotenv->overload(true);

            // set TimeZone
            date_default_timezone_set(getenv('TIMEZONE'));

            // Initiate the MeshviewerGenerator
            $meshGenerator = new MeshviewerGenerator();

            $ret_status[$env] = json_decode($meshGenerator->executeTask());

            if(file_exists("data/nodelist.json")) {
                
                if(!is_dir("data/$env/")) mkdir("data/$env/", 0700);
                if(file_exists("data/nodes.json")) rename("data/nodes.json", "data/$env/nodes.json");
                if(file_exists("data/graph.json")) rename("data/graph.json", "data/$env/graph.json");
                if(file_exists("data/nodelist.json")) rename("data/nodelist.json", "data/$env/nodelist.json");
                if(file_exists("data/meshviewer.json")) rename("data/meshviewer.json", "data/$env/meshviewer.json");

            }
            
        }
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