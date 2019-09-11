<?php

namespace ISPServerfarm\UnifiMeshviewer;

use UniFi_API\Client as UnifiClient;

class MeshviewerGenerator{

    protected $unifi_url    = "";
    protected $unifi_user   = "";
    protected $unifi_pass   = "";
    protected $unifi_site   = "";
    protected $client = null;
    protected $client_debug = null;
    protected $client_loginresult = null;
    protected $alldevices = null;
    protected $allaccesspoints = [];

    private $nodelist = [];
    private $nodes_array = [];
    private $meshview = null;
    private $graph = null;
    private $graphlinks = [];
    private $graphnodes = [];
    private $model = null;
    private $link = [];
    private $links = [];
    private $radio_stats = null;
    private $radio_stat0 = null;
    private $radio_stat1 = null;
    private $ap_metadata = null;
    private $firmware_base = 'Ubiquiti Networks';
    private $writeStatus = [];

    public function __construct()
    {
        $this->client = new UnifiClient(
            getenv('UNIFI_USER'),
            getenv('UNIFI_PASS'),
            getenv('UNIFI_URL'),
            getenv('UNIFI_ZONE'),
            getenv('UNIFI_VERSION'));
    }

    public function outputDebug(){
        echo $this->unifi_pass;
        echo "debug";
    }

    public function enableDebug(){
        $this->client_debug_mode = $this->client->set_debug(true);
    }

    private function addLink($device){
        $this->link[$device->serial]["type"] = "other";
        $this->link[$device->serial]["source"] = strtolower($device->serial);
        $this->link[$device->serial]["target"] = getenv('GATEWAY_ID');
        $this->link[$device->serial]["source_tq"] = 1;
        $this->link[$device->serial]["target_tq"] = 1;
        $this->link[$device->serial]["source_addr"] = $device->mac;
        $this->link[$device->serial]["target_addr"] = getenv('GATEWAY_MAC');
    }

    private function addGraph($device, $index){  
        $this->graphlinks[$index]["source"] = $index;
        $this->graphlinks[$index]["target"] = 0;
        $this->graphlinks[$index]["tq"] = 1;
        $this->graphlinks[$index]["type"] = "other";     

        $this->graphnodes[$index]["id"] = $device->mac;   
        $this->graphnodes[$index]["node_id"] = strtolower($device->serial);    
    }

    private function getLinks(){
        $return = [];
        foreach ($this->link as $link) {
            $return[] = $link;
        }
        return $return;
    }

    private function getGraphLinks(){
        $return = [];
        foreach ($this->graphlinks as $link) {
            $return[] = $link;
        }
        return $return;
    }    

    private function getGraphNodes(){
        $return = [];
        foreach ($this->graphnodes as $link) {
            $return[] = $link;
        }
        return $return;
    }    

    private function login(){
        if (is_null($this->client_loginresult)){
            $this->client_loginresult = $this->client->login();
        }
    }

    private function getDevices(){
        $this->login();
        return $this->alldevices = $this->client->list_devices();
    }

    private function getAccessPoints(){
        $this->getDevices();
        foreach ($this->alldevices as $device) {
            if ($device->type === 'uap'){
                $this->writeDeviceCache($device);
                $this->writeDeviceFile($device);
                $this->allaccesspoints[$device->serial] = $device;
            }
        }
        return $this->allaccesspoints;
    }

    private function getAllAccessPoints(){
        if (empty($this->allaccesspoints)){
            $this->getAccessPoints();
        }
        return $this->allaccesspoints;
    }

    private function getAccessPointBySerial(string $serial){
        $tmp = $this->getAllAccessPoints();
        return $tmp[$serial];
    }

    private function getModel($model){
        switch ($model) {
            case "U7MSH":
                return "UAP-AC-Mesh";
                break;
            case "U7MP":
                return "UAP-AC-M-Pro";
                break;
            case "U7PG2":
                return "UAP-AC-Pro Gen2";
                break;
            case "U7LT":
                return "UAP-AC-Lite";
                break;
            case "U7LR":
                return "UAP-AC-LR";
                break;
            case "U7P":
                return "UAP-Pro";
                break;
            default:
                return $model;
        }
    }

    private function getPosition(object $device){
        if ((isset($device->x) and isset($device->y)) and (!empty($device->x) and !empty($device->y))){
            $return = [];
            $return['lat']  = $device->x;
            $return['long'] = $device->y;
            return $return;
        } else {
            return false;
        }
    }

    private function buildNodesForNodelist(){
        $devices = $this->getAllAccessPoints();
        $return = [];
        foreach ($devices as $device) {
            $ap_metadata = $this->loadDeviceByDeviceID($device->serial);
            $position = $this->getPosition($device);
            if ($ap_metadata){
                $node = [];
                $node['id'] = $device->serial;
                $node['name'] = $ap_metadata['name'];
                if ($position){
                    $node['position']['longitude']  = $position['long'];
                    $node['position']['latitude']   = $position['lat'];
                }
                if ($device->state == 1){
                    $node['status']['online'] = true;
                    $node['status']['lastcontact'] = date(DATE_ISO8601,$device->last_seen);
                } else {
                    $node['status']['online'] = false;
                    $node['status']['lastcontact'] = $ap_metadata['last_seen'];
                }
                $node['status']['clients'] = $device->num_sta;
                $return[] = $node;
                unset($node);
            }
        }
        #$return[] = $this->buildGatewayNodeForNodelist();
        return $return;
    }

    private function buildNodesForMeshviewerList(){
        $devices = $this->getAllAccessPoints();
        $return = [];
        foreach ($devices as $device) {
            $this->addLink($device);
            $ap_metadata = $this->loadDeviceByDeviceID($device->serial);
            $position = $this->getPosition($device);
            if (isset($ap_metadata['name'])){
                $name = $ap_metadata['name'];
            } elseif (isset($device->name)) {
                $name = $device->name;
            } else {
                $name = "Unnamed";
            }
            $node = [];
            if ($device->state == 1){
                $stats = $device->stat;
                $radio_stats = (array) $device->radio_table_stats;
                $radio_stat0 = $radio_stats[0];
                $radio_stat1 = $radio_stats[1];
                $node['firstseen'] = $ap_metadata['first_seen'];
                $node['lastseen'] = date(DATE_ISO8601, $device->last_seen);
                $node['is_online'] = true;
                $node['is_gateway'] = false;
                $node['clients'] = $device->num_sta;
                $node['clients_wifi24'] = $radio_stat0->num_sta;
                $node['clients_wifi5'] = $radio_stat1->num_sta;
                $node['clients_other'] = 0;

                $stats = $device->sys_stats;
                $avg = $stats->loadavg_1;
                $node['loadavg'] = floatval($avg);
                $node['memory_usage'] = $stats->mem_used/$stats->mem_total;
            } else {
                $node['firstseen'] = $ap_metadata['first_seen'];
                $node['lastseen'] = $ap_metadata['last_seen'];
                $node['is_online'] = false;
                $node['is_gateway'] = false;
                $node['clients'] = 0;
                $node['clients_wifi24'] = 0;
                $node['clients_wifi5'] = 0;
                $node['clients_other'] = 0;
                $node['loadavg'] = 0;
                $node['memory_usage'] = 0;
            }
            $node['uptime']             = $ap_metadata['uptime'];
            $node['gateway_nexthop']    = getenv('GATEWAY_ID');
            $node['gateway']            = getenv('GATEWAY_NEXTHOP');
            $node['node_id']            = strtolower($device->serial);
            $node['mac']                = $device->mac;
            $node['addresses']          = [$device->ip];
            $node['site_code']          = getenv('FREIFUNK_SITEID');
            $node['hostname']           = $name;
            $node['owner']              = $ap_metadata['owner'];
            if ($position){
                $node['location']['longitude']  = $position['long'];
                $node['location']['latitude']   = $position['lat'];
            }
            $node['firmware']['base']           = $this->firmware_base;
            $node['firmware']['release']        = $device->version;
            $node['autoupdater']['enabled']     = false;
            $node['autoupdater']['release']     = 'stable';
            $node['nproc'] = 1;
            $node['model'] = $this->getModel($device->model);
            $node['vpn'] = false;
            $return[] = $node;
            unset($node, $name, $ap_metadata);
        }
        #$return[] = $this->buildGatewayNodeForMeshviewerlist();
        return $return;
    }

    private function buildNodesForHopglassList(){
        $devices = $this->getAllAccessPoints();
        $return = [];

        $this->graphnodes[0]["id"]      = getenv('GATEWAY_MAC');
        $this->graphnodes[0]["node_id"] = getenv('GATEWAY_ID'); 

        $i=1;
        foreach ($devices as $device) {
            $this->addGraph($device, $i++);
            $ap_metadata = $this->loadDeviceByDeviceID($device->serial);
            $position = $this->getPosition($device);
            if (isset($ap_metadata['name'])){
                $name = $ap_metadata['name'];
            } elseif (isset($device->name)) {
                $name = $device->name;
            } else {
                $name = "Unnamed";
            }
            $node = [];

            $node['nodeinfo']['software']['autoupdater']['branch']  = 'stable';
            $node['nodeinfo']['software']['autoupdater']['enabled'] = false;
            $node['nodeinfo']['software']['fastd']['enabled']       = false;            
            $node['nodeinfo']['software']['firmware']['base']       = $this->firmware_base;
            $node['nodeinfo']['software']['firmware']['release']    = $device->version;
            $node['nodeinfo']['network']['addresses']               = [$device->ip];
            $node['nodeinfo']['network']['mac']                     = $device->mac;
            if ($position){
                $node['nodeinfo']['location']['longitude']          = $position['long'];
                $node['nodeinfo']['location']['latitude']           = $position['lat'];
            }            
            $node['nodeinfo']['owner']['contact']                   = $ap_metadata['owner'];
            $node['nodeinfo']['system']['role']                     = 'node';
            $node['nodeinfo']['system']['site_code']                = getenv('FREIFUNK_SITEID');
            $node['nodeinfo']['node_id']                            = strtolower($device->serial);
            $node['nodeinfo']['hostname']                           = $name;
            $node['nodeinfo']['hardware']['model']                  = $this->getModel($device->model);
            $node['nodeinfo']['hardware']['nproc']                  = 1;

            if ($device->state == 1){
                $stats = $device->stat;
                $radio_stats = (array) $device->radio_table_stats;
                $radio_stat0 = $radio_stats[0];
                $radio_stat1 = $radio_stats[1];


                $node['flags']['online']                            = true;
                $node['firstseen']                                  = $ap_metadata['first_seen'];
                $node['lastseen']                                   = date(DATE_ISO8601, $device->last_seen);
                $node['statistics']['clients']                      = $device->num_sta;

                $stats = $device->sys_stats;
                $avg = $stats->loadavg_1;

                $node['statistics']['loadavg']                      = floatval($avg);
                $node['statistics']['memory_usage']                 = $stats->mem_used/$stats->mem_total;

            } else {
                $node['flags']['online']                            = false;
                $node['firstseen']                                  = $ap_metadata['first_seen'];
                $node['lastseen']                                   = $ap_metadata['last_seen'];
                $node['statistics']['clients']                      = 0;
                $node['statistics']['loadavg']                      = 0;
                $node['statistics']['memory_usage']                 = 0;
            }

            $node['statistics']['uptime']             = $ap_metadata['uptime'];
            $node['statistics']['gateway_nexthop']    = getenv('GATEWAY_ID');
            $node['statistics']['gateway']            = getenv('GATEWAY_NEXTHOP');

            $return[] = $node;
            unset($node, $name, $ap_metadata);
        }
        #$return[] = $this->buildGatewayNodeForMeshviewerlist();
        return $return;
    }

    //Deprecated
    private function buildGatewayNodeForNodelist(){
        $return = [];
        $return['id'] = getenv('GATEWAY_ID');
        $return['name'] = getenv('GATEWAY_NAME');
        $return['status']['online'] = true;
        $return['status']['lastcontact'] = date(DATE_ISO8601);
        $return['status']['clients'] = 0;
        return $return;
    }

    //Deprecated
    private function buildGatewayNodeForMeshviewerlist(){
        $return = [];
        #print_r(@file_get_contents('/proc/uptime'));
        #echo print_r($this->Uptime());

        $load = sys_getloadavg();

        $return['firstseen'] = date(DATE_ISO8601,time(getenv('GATEWAY_FIRSTSEEN')));
        $return['lastseen'] = date(DATE_ISO8601);
        $return['is_online'] = true;
        $return['is_gateway'] = true;
        $return['clients'] = 0;
        $return['clients_wifi24'] = 0;
        $return['clients_wifi5'] = 0;
        $return['clients_other'] = 0;
        $return['rootfs_usage'] = 0;
        $return['loadavg'] = $load[0];
        $return['memory_usage'] = 0;
        $return['node_id'] = getenv('GATEWAY_ID');
        $return['mac'] = getenv('GATEWAY_MAC');
        $return['addresses'] = [getenv('GATEWAY_IPADDRESS')];
        $return['hostname'] = getenv('GATEWAY_NAME');
        $return['firmware']['base'] = $this->firmware_base;
        $return['firmware']['release'] = "RELEASE";
        $return['autoupdater']['enabled'] = false;
        $return['nproc'] = 2;
        $return['vpn'] = true;
        return $return;
    }

    private function buildMeshviewer(){
        $devices = $this->getAllAccessPoints();
        $return = [];

    }

    private function buildNodelist(){
        $this->nodelist['version'] = '1.0.1';
        $this->nodelist['updated_at'] = date(DATE_ISO8601);
        $this->nodelist['nodes'] = $this->buildNodesForNodelist();
        return $this->nodelist;
    }

    private function buildMeshviewerList(){
        $this->meshview['timestamp'] = date(DATE_ISO8601);
        $this->meshview['nodes'] = $this->buildNodesForMeshviewerList();
        $this->meshview['links'] = $this->getLinks();    
        return $this->meshview;
    }

    private function buildHopglassList(){
        $this->meshview['version'] = 2;
        $this->meshview['nodes'] = $this->buildNodesForHopglassList();
        $this->meshview['timestamp'] = date(DATE_ISO8601);
        return $this->meshview;
    }

    private function buildGraphList(){
        $this->graph['timestamp'] = date(DATE_ISO8601);
        $this->graph['version'] = 1;
        $this->graph['batadv']['multigraph'] = false;
        $this->graph['batadv']['directed'] = true;
        $this->graph['batadv']['nodes'] = $this->getGraphNodes();
        $this->graph['batadv']['links'] = $this->getGraphLinks(); 
        $this->graph['graph'] = null;
        return $this->graph;
    }

    private function outputNodelist(){
        return json_encode($this->buildNodelist(),JSON_PRETTY_PRINT);
    }

    private function outputMeshviewerList(){
        return json_encode($this->buildMeshviewerList(),JSON_PRETTY_PRINT);
    }

    private function outputHopglassList(){
        return json_encode($this->buildHopglassList(),JSON_PRETTY_PRINT);
    }
    
    private function outputGraphList(){
        return json_encode($this->buildGraphList(),JSON_PRETTY_PRINT);
    }    

    public function writeNodeListFile(){
        $return_nodeList = $this->outputNodelist();
        $respone = file_put_contents('data/nodelist.json', $return_nodeList);
        if ($respone){
            $this->writeStatus['nodelist'] = true;
        } else {
            $this->writeStatus['nodelist'] = false;
        }
    }

    public function writeMeshviewerListFile(){
        $return_nodeList = $this->outputMeshviewerList();
        $respone = file_put_contents('data/meshviewer.json', $return_nodeList);
        if ($respone){
            $this->writeStatus['meshviewer'] = true;
        } else {
            $this->writeStatus['meshviewer'] = false;
        }
    }

    public function writeHopglassListFile(){
        $return_nodeList = $this->outputHopglassList();
        $respone = file_put_contents('data/nodes.json', $return_nodeList);
        if ($respone){
            $this->writeStatus['hopglass'] = true;
        } else {
            $this->writeStatus['hopglass'] = false;
        }
    }

    public function writeHopglassGraphFile(){
        $return_nodeList = $this->outputGraphList();
        $respone = file_put_contents('data/graph.json', $return_nodeList);
        if ($respone){
            $this->writeStatus['graph'] = true;
        } else {
            $this->writeStatus['graph'] = false;
        }
    }

    public function writeDeviceCache($device){
        if ($device->state == 1){
            file_put_contents("../cache/".$device->serial.".json", json_encode($device,JSON_PRETTY_PRINT));
        }
    }

    public function writeDeviceFile(object $device){
        if ($this->checkDeviceFileExists($device->serial)){
            if ($device->state == 1){
                $deviceData = $this->loadDeviceByDeviceId($device->serial);
                $deviceData['name_internal'] = isset($device->name) ? $device->name : "Unkown";
                $deviceData['ip'] = $device->ip;
                $deviceData['last_seen'] = date(DATE_ISO8601,$device->last_seen);
                $deviceData['uptime'] = date(DATE_ISO8601,time()-$device->uptime);
                $deviceData['owner'] = getenv('OWNER_EMAIL');
            }
        } else {
            $deviceData = [];
            $deviceData['name'] = "";
            $deviceData['name_internal'] = isset($device->name) ? $device->name : "Unkown";
            $deviceData['nodeid'] = $device->serial;
            $deviceData['mac'] = $device->mac;
            $deviceData['ip'] = $device->ip;
            $deviceData['first_seen'] = date(DATE_ISO8601);
            $deviceData['last_seen'] = isset($device->last_seen) ? date(DATE_ISO8601,$device->last_seen) : date(DATE_ISO8601,time(2019-01-01));
            $deviceData['uptime'] = isset($device->uptime) ? date(DATE_ISO8601,time()-$device->uptime) : date(DATE_ISO8601,time()-1);
            $deviceData['owner'] = getenv('OWNER_EMAIL');
        }
        isset($deviceData) ? $this->saveDeviceFile($device->serial, $deviceData) : "";

    }

    private function saveDeviceFile(string $deviceId, array $deviceData = []){
        if (!empty($deviceData)){
            file_put_contents("../devices/".$deviceId.".json", json_encode($deviceData,JSON_PRETTY_PRINT));
        }
    }

    public function loadDeviceCacheByDeviceID(string $deviceId){
        if(file_exists("../cache/".$deviceId.".json")){
            return json_decode(file_get_contents("../cache/".$deviceId.".json"), true);
        } else {

        }
    }

    public function loadDeviceByDeviceId(string $deviceId){
        if(file_exists("../devices/".$deviceId.".json")){
            return json_decode(file_get_contents("../devices/".$deviceId.".json"),true);
        }
    }

    public function checkDeviceFileExists(string $deviceId){
        if(file_exists("../devices/".$deviceId.".json")){
            return true;
        } else {
            return false;
        }
    }

    public function checkDeviceCacheFileExists(string $deviceId){
        if(file_exists("../cache/".$deviceId.".json")){
            return true;
        } else {
            return false;
        }
    }

    private function returnWriteStatus(){
        $return = [];
        if(getenv('VIEWER')=="hopglass") {
            if (isset($this->writeStatus['nodelist']) and isset($this->writeStatus['nodes']) and isset($this->writeStatus['graph'])){
                if (isset($this->writeStatus['nodelist']) === true and $this->writeStatus['nodes'] === true and $this->writeStatus['graph'] === true) {
                    $return['status'] = true;

                } else {
                    $return['status'] = false;

                }
                $return['json']['nodelist'] = $this->writeStatus['nodelist'];
                $return['json']['nodes'] = $this->writeStatus['nodes'];
                $return['json']['graph'] = $this->writeStatus['graph'];
            } else {
                $return['status'] = false;
                if (!isset($this->writeStatus['nodelist'])){
                    $return['json']['nodelist'] = "status unknown";
                } else {
                    $return['json']['nodelist'] = $this->writeStatus['nodelist'];
                }
                if (!isset($this->writeStatus['nodes'])){
                    $return['json']['nodes'] = "status unknown";
                } else {
                    $return['json']['nodes'] = $this->writeStatus['nodes'];
                }                
                if (!isset($this->writeStatus['graph'])){
                    $return['json']['graph'] = "status unknown";
                } else {
                    $return['json']['graph'] = $this->writeStatus['graph'];
                }
            }
        }
        else {
            if (isset($this->writeStatus['nodelist']) and isset($this->writeStatus['meshviewer'])){
                if ($this->writeStatus['nodelist'] === true and $this->writeStatus['meshviewer'] === true) {
                    $return['status'] = true;

                } else {
                    $return['status'] = false;

                }
                $return['json']['nodelist'] = $this->writeStatus['nodelist'];
                $return['json']['meshviewer'] = $this->writeStatus['meshviewer'];
            } else {
                $return['status'] = false;
                if (!isset($this->writeStatus['nodelist'])){
                    $return['json']['nodelist'] = "status unknown";
                } else {
                    $return['json']['nodelist'] = $this->writeStatus['nodelist'];
                }
                if (!isset($this->writeStatus['meshviewer'])){
                    $return['json']['meshviewer'] = "status unknown";
                } else {
                    $return['json']['meshviewer'] = $this->writeStatus['meshviewer'];
                }
            }
        }
        return $return;
    }

    public function executeTask(){
        $this->writeNodeListFile();
        if(getenv('VIEWER')=="hopglass") {
            $this->writeHopglassListFile();    
            $this->writeHopglassGraphFile();
        }
        else {            
            $this->writeMeshviewerListFile();
        }
        return json_encode($this->returnWriteStatus(),JSON_PRETTY_PRINT);
    }
}