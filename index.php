<?php
global $volume;
$volume = 60;
require("phpMQTT/phpMQTT.php");
if(isset($_POST['volume'])) {
    $mqtt = new phpMQTT("infra.rzl", 1883, "onkyo-start-skript");
    if(!$mqtt->connect()){
        exit(1);
    }
    $mqtt->publish("/service/onkyo/set/volume",$_POST['volume'],0);
    exit();

} else if($_GET['volume'] == "up" || $_GET['volume'] == "down") {
    $mqtt = new phpMQTT("infra.rzl", 1883, "onkyo-start-skript");
    if(!$mqtt->connect()){
        exit(1);
    }
    if($_GET['volume'] == "up") {
        $mqtt->publish("/service/onkyo/command","MVLUP",0);
    } else if ($_GET['volume'] == "down") {
        $mqtt->publish("/service/onkyo/command","MVLDOWN",0);
    }

} else if($_GET['musicNow'] == "true") {
    require("PHP-MPD-Client/mpd/MPD.php");
    
    \PHPMPDClient\MPD::connect("", "127.0.0.1");
    $status = \PHPMPDClient\MPD::status();
    $statusParsed = array();
    foreach($status['values'] as $s) {
        $s = explode(": ", $s);
        $statusParsed[$s[0]] = trim($s[1]);
    }
    echo "ensure onkyo has power, just in case…<br>";
    file_get_contents("http://infra.rzl:8080/CMD?pca301_music=ON");
    
    if($statusParsed['playlistlength'] == 0) {
         echo "Playlist is empty, inserting a soing…<br>";
         \PHPMPDClient\MPD::add("Welle Erdball/Tanzpalast 2000/03 - Welle_ Erdball - Wo kommen all die Geister her.mp3");
    }
    if($statusParsed['state'] != "play") {
         echo "MPD is not playing, start playing now…<br>";
        \PHPMPDClient\MPD::send("play");
    }

    $mqtt = new phpMQTT("infra.rzl", 1883, "onkyo-start-skript");
    if(!$mqtt->connect()){
        exit(1);
    }
    echo "command onkyo to turn on and start the mpd stream…<br>";
    $mqtt->publish("/service/onkyo/command","SLI2B",0);
    $mqtt->publish("/service/onkyo/command","NPR01",0);
    echo '<a href="/mpd/">MPD Webinterface</a>';
    $mqtt->close();
    exit();
}

function mainText() {
    echo 'Volume <a href="?volume=up">up</a> <a href="?volume=down">down</a><br>';
    echo '<a href="/mpd/">MPD Webinterface</a><br><br>';
    echo '<a href="?musicNow=true">I want music now</a>';
?>
<script src="js/external/jquery/jquery.js"></script>
<script src="js/jquery-ui.min.js"></script>
<script src="js/jquery.ui.touch-punch.min.js"></script>
<script src="js/mqttws31.min.js"></script>
<link rel="stylesheet" href="js/jquery-ui.min.css">
<link rel="stylesheet" href="js/jquery-ui.theme.min.css">

<p>
  <label for="volume-cur">Onkyo Volume:</label>
  <input type="text" id="volume-cur" readonly style="border:0; color:green; font-weight:bold;" value="loading JS…">
</p>
<div id="volume-slider"><div id="custom-handle" class="ui-slider-handle"></div></div>

<p>
  <label for="onkyo-input">Onkyo Input:</label>
  <select id="onkyo-input">
    <option value="" disabled selected>Unknown</option>
    <option value="SLI2B">Netzwerk</option>
    <option value="SLI10">Rednerpult</option>
    <option value="SLI11">Tisch</option>
    <option value="SLI01">Chromecast</option>
  </select>
</p>
<br><br><br>(Ab hier work in progress)<br><br><br>
<style>
#custom-handle {
  width: 3em;
  height: 1.6em;
  top: 50%;
  margin-top: -.8em;
  text-align: center;
  line-height: 1.6em;
}
</style> 
<script>
  var setVolumeTimeout = null;
  var handle = $( "#custom-handle" );	    
  var mqtt;
  var isSliding = false;
  var volume = "unknown";
  $( function() {
    handle.text("<?php global $volume; echo $volume; ?>");
    $( "#volume-slider" ).slider({
      range: "max",
      min: 0,
      max: 50,
      value: <?php global $volume; echo $volume; ?>,
      slide: function( event, ui ) {
        handle.text(ui.value);
        if(setVolumeTimeout != null) {
          clearTimeout(setVolumeTimeout);
        }
        setVolumeTimeout = setTimeout(function(){
	  setVolumeTimeout = null;
          message = new Paho.MQTT.Message(ui.value+"");
	  message.destinationName = "/service/onkyo/set/volume";
	  mqtt.send(message);
	}, 50);
      },
      start: function( e,u ) { isSliding = true; },
      stop: function( e,u ) { isSliding = false; }
    });
    $("#onkyo-input").on('change', function(){
	message = new Paho.MQTT.Message($("#onkyo-input").val());
	message.destinationName = "/service/onkyo/command";
	mqtt.send(message);
    });
    var reconnectTimeout = 2000;

    function MQTTconnect() {
        $( "#volume-cur" ).val("connecting…");
        mqtt = new Paho.MQTT.Client(
                        "mpd.rzl",
                        1884,
                        "" //"web_" + parseInt(Math.random() * 100, 10)
                        );
        var options = {
            timeout: 60,
            useSSL: false,
            cleanSession: true,
            onSuccess: onConnect,
            onFailure: function (message) {
                $( "#volume-cur" ).val("connecting failed");
                setTimeout(MQTTconnect, reconnectTimeout);
            }
        };

        mqtt.onConnectionLost = onConnectionLost;
        mqtt.onMessageArrived = onMessageArrived;

        //console.log("Host="+ host + ", port=" + port + " TLS = " + useTLS + " username=" + username + " password=" + password);
        mqtt.connect(options);
    }

    function onConnect() {
	$( "#volume-cur" ).val("requesting volume…");
        mqtt.subscribe("/service/onkyo/status/volume", {qos: 0});
	mqtt.subscribe("/service/onkyo/status/audio-muting", {qos: 0});
	mqtt.subscribe("/service/onkyo/status/input-selector", {qos: 0});
    }

    function onConnectionLost(response) {
        setTimeout(MQTTconnect, reconnectTimeout);
        $( "#volume-cur" ).val("disconnected");
    };

    function onMessageArrived(message) {
        if(message.destinationName == "/service/onkyo/status/volume") {
          volume = JSON.parse(message.payloadString).val;
          $( "#volume-cur" ).val(volume);
          if(!isSliding) {
            $( "#volume-slider" ).slider("value", volume);
            handle.text(volume);
          }
        } else if (message.destinationName == "/service/onkyo/status/audio-muting") {
          var newState = JSON.parse(message.payloadString).val;
          if(newState == "on") {
            $( "#volume-cur" ).val("mute");
          } else if (newState == "off") {
            $( "#volume-cur" ).val(volume);
          }
	} else if (message.destinationName == "/service/onkyo/status/input-selector") {
	  var newInput = JSON.parse(message.payloadString).onkyo_raw;
	  $("#onkyo-input").val(newInput);
	}
    }
    MQTTconnect();
  } );
</script>
<?php
    exit();
}
$mqtt = new phpMQTT("infra.rzl", 1883, "onkyo-start-skript");
if(!$mqtt->connect()){
	exit(1);
}
function handleSuccess() {
    static $sucessCount = 0;
    $sucessCount++;
    if($sucessCount == 3) {
        mainText();
    }
}
function procpower($topic,$msg){
    $msg = json_decode($msg, true);
    if($msg['val'] == "on") {
        handleSuccess();
    } else {
        echo "Onkyo is on standby <a href=\"?musicNow=true\">fix it!</a><br>";
        mainText();
    }
}
function procinput($topic,$msg){
    $msg = json_decode($msg, true);
    if($msg['val'] == "network") {
        handleSuccess();
    } else {
        echo "Onkyo is on wrong intput <a href=\"?musicNow=true\">fix it!</a><br>";
        mainText();
    }
}
function procNLT($topic,$msg){
    $msg = json_decode($msg, true);
    if($msg['val'] == "0122000000000001000100") {
        handleSuccess();
    } else {
        echo "Onkyo is on wrong intput <a href=\"?musicNow=true\">fix it!</a><br>";
        mainText();
    }
}
function procVol($topic,$msg){
    $msg = json_decode($msg, true);
    global $volume;
    $volume = $msg['val'];
}
$topics2['/service/onkyo/status/volume'] = array("qos"=>0, "function"=>"procVol");
$mqtt->subscribe($topics2,0);
$mqtt->proc(); $mqtt->proc();
$topics['/service/onkyo/status/system-power'] = array("qos"=>0, "function"=>"procpower");
$topics['/service/onkyo/status/input-selector'] = array("qos"=>0, "function"=>"procinput");
$topics['/service/onkyo/status/NLT'] = array("qos"=>0, "function"=>"procNLT");
/*
/service/onkyo/status/system-power {"onkyo_raw": "PWR01", "val": "on"}
/service/onkyo/status/input-selector {"onkyo_raw": "SLI2B", "val": "network"}
/service/onkyo/status/NLT {"onkyo_raw": "NLT0122000000000001000100", "val": "0122000000000001000100"}
*/

$mqtt->subscribe($topics,0);
while($mqtt->proc()){}
$mqtt->close();
?>
