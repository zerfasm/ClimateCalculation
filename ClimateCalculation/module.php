<?php

require_once __DIR__.'/../libs/traits.php';  // Allgemeine Funktionen

// CLASS ClimateCalculation
class ClimateCalculation extends IPSModule
{
    use ProfileHelper, DebugHelper;

    public function Create()
    {
        //Never delete this line!
        parent::Create();

        // Outdoor variables
        $this->RegisterPropertyInteger('TempOutdoor', 0);
        $this->RegisterPropertyInteger('HumyOutdoor', 0);
        $this->RegisterPropertyInteger('DewPointOutdoor', 0);
        $this->RegisterPropertyInteger('WaterContentOutdoor', 0);
        
        // Indoor variables
        $this->RegisterPropertyInteger('TempIndoor', 0);
        $this->RegisterPropertyInteger('HumyIndoor', 0);
        $this->RegisterPropertyFloat('TempDiffWallIndoor', 0);
        
         // Window variables
        $this->RegisterPropertyInteger('WindowValue', 0);
        $this->RegisterPropertyBoolean('CreateAir', false);
        $this->RegisterPropertyInteger('DiffLimit', 5);
        $this->RegisterPropertyBoolean('TTSAlexa', false);
        $this->RegisterPropertyString('AlexaID', "");
	$this->RegisterPropertyInteger('AlexaVolume', 40);
        $this->RegisterPropertyString('NameRoom', "");
        
        // Settings
        $this->RegisterPropertyInteger('UpdateTimer', 5);
        $this->RegisterPropertyBoolean('CreateDewPoint', false);
        $this->RegisterPropertyBoolean('CreateWaterContent', false);
        $this->RegisterPropertyBoolean('CreateTF70', false);
        $this->RegisterPropertyBoolean('CreateTF80', false);
        $this->RegisterPropertyBoolean('CreateAWValue', false);
        $this->RegisterPropertyBoolean('CreateMould', false);
        
        // Update trigger
        $this->RegisterTimer('UpdateTrigger', 0, "SCHB_Update(\$_IPS['TARGET']);");
    }

    public function ApplyChanges()
    {
        //Never delete this line!
        parent::ApplyChanges();

        // Update Trigger Timer
        $this->SetTimerInterval('UpdateTrigger', 1000 * 60 * $this->ReadPropertyInteger('UpdateTimer'));

        // Profile "SCHB.AirOrNot"
        $association = [
            [0, 'Nicht Lüften!', 'Window-0', 0x00FF00],
            [1, 'Lüften!', 'Window-100', 0xFF0000],
        ];
        $this->RegisterProfile(vtBoolean, 'SCHB.AirOrNot', 'Window', '', '', 0, 0, 0, 0, $association);

        // Profile "SCHB.WaterContent"
        $association = [
            [0, '%0.2f', '', ''],
        ];
        $this->RegisterProfile(vtFloat, 'SCHB.WaterContent', 'Drops', '', ' g/m³', 0, 0, 0, 0, $association);
        
         // Profile "SCHB.Schimmelgefahr"
        $association = [
            [0, 'Keine Gefahr', '', 0x00FF00],
            [1, 'Gefahr', '', 0xffa500],
            [2, 'Schimmel', '', 0xFF0000],
        ];
        $this->RegisterProfile(vtInteger, 'SCHB.Schimmelgefahr', 'Information', '', '', 0, 0, 0, 0, $association);

        // Profile "SCHB.Difference"
        $association = [
            [-500, '%0.2f %%', 'Window-0', 0x00FF00],
            [0, '%0.2f %%', 'Window-0', 0x00FF00],
            [0.01, '+%0.2f %%', 'Window-100', 0xffa500],
            [10, '+%0.2f %%', 'Window-100', 0xFF0000],
        ];
        $this->RegisterProfile(vtFloat, 'SCHB.Difference', 'Window', '', '', 0, 0, 0, 2, $association);
        
        // Profile "SCHB.Ventilate"
        $association = [
            [0, 'Nicht gelüftet', 'Window-0', 0xFF0000],
            [1, 'Gelüftet', 'Window-100', 0x00FF00],
        ];
        $this->RegisterProfile(vtInteger, 'SCHB.Ventilate', 'Window', '', '', 0, 0, 0, 0, $association);
        
        // Ergebnis & Hinweis & Differenz
        $this->MaintainVariable('Hint', 'Hinweis', vtBoolean, 'SCHB.AirOrNot', 1, true);
        $this->MaintainVariable('Result', 'Ergebnis', vtString, 'SCHB.Ergebnis', 2, true);
        $this->MaintainVariable('Difference', 'Differenz', vtFloat, 'SCHB.Difference', 3, true);
        
        // Taupunkt
        $create = $this->ReadPropertyBoolean('CreateDewPoint');
        $this->MaintainVariable('DewPointIndoor', 'Taupunkt Innen', vtFloat, '~Temperature', 5, $create);

        // Wassergehalt (WaterContent)
        $create = $this->ReadPropertyBoolean('CreateWaterContent');
        $this->MaintainVariable('WaterContentIndoor', 'Wassergehalt Innen', vtFloat, 'SCHB.WaterContent', 7, $create);
        
        // TF-70
        $create = $this->ReadPropertyBoolean('CreateTF70');
        $this->MaintainVariable('TF70', 'TF-70', vtFloat, '~Temperature', 8, $create); 
        
        // TF-80
        $create = $this->ReadPropertyBoolean('CreateTF80');
        $this->MaintainVariable('TF80', 'TF-80', vtFloat, '~Temperature', 9, $create); 
        
        // AW-Wert
        $create = $this->ReadPropertyBoolean('CreateAWValue');
        $this->MaintainVariable('AWValue', 'AW-Wert', vtFloat, 'aw.value', 10, $create); 
        
        //Schimmelgefahr
        $create = $this->ReadPropertyBoolean('CreateMould');
        $this->MaintainVariable('Mould', 'Schimmelgefahr', vtInteger, 'SCHB.Schimmelgefahr', 11, $create); 
        
        //Gelüftet
        $create = $this->ReadPropertyBoolean('CreateAir');
        $this->MaintainVariable('Ventilate', 'Gelüftet', vtInteger, 'SCHB.Ventilate', 12, $create); 
    }

    /**
     * This function will be available automatically after the module is imported with the module control.
     * Using the custom prefix this function will be callable from PHP and JSON-RPC through:.
     *
     * SCHB_Update($id);
     */
    public function Update()
    {
        $result = 'Ergebnis konnte nicht ermittelt werden!';
        // Daten lesen
        $state = true;
        
        // Temp Outdoor
        $to = $this->ReadPropertyInteger('TempOutdoor');
        if ($to != 0) {
            $to = GetValue($to);
        } else {
            $this->SendDebug('UPDATE', 'Temperature Outdoor not set!');
            $state = false;
        }
        
        // Humidity Outdoor
        $ho = $this->ReadPropertyInteger('HumyOutdoor');
        if ($ho != 0) {
            $ho = GetValue($ho);
            // Kann man bestimmt besser lösen
            if ($ho < 1) {
                $ho = $ho * 100.;
            }
        } else {
            $this->SendDebug('UPDATE', 'Humidity Outdoor not set!');
            $state = false;
        }
        
        // Water Content Outdoor
        $wco = $this->ReadPropertyInteger('WaterContentOutdoor');
        if ($wco != 0) {
            $wco = GetValue($wco);
        } else {
            $this->SendDebug('UPDATE', 'Water Content Outdoor not set!');
            $state = false;
        }
        
        // Temp Indoor
        $ti = $this->ReadPropertyInteger('TempIndoor');
        if ($ti != 0) {
            $ti = GetValue($ti);
        } else {
            $this->SendDebug('UPDATE', 'Temperature Indoor not set!');
            $state = false;
        }
        
        // Humidity Indoor
        $hi = $this->ReadPropertyInteger('HumyIndoor');
        if ($hi != 0) {
            $hi = GetValue($hi);
            // Kann man bestimmt besser lösen
            if ($hi < 1) {
                $hi = $hi * 100.;
            }
        } else {
            $this->SendDebug('UPDATE', 'Humidity Indoor not set!');
            $state = false;
        }
        // All okay
        if ($state == false) {
            $this->SetValueString('Result', $result);

            return;
        }

        // Minus oder Plus ;-)
        if ($ti >= 0) {
            // Plustemperaturen
            $ao = 7.5;
            $bo = 237.7;
            $ai = $ao;
            $bi = $bo;
        } else {
            // Minustemperaturen
            $ao = 7.6;
            $bo = 240.7;
            $ai = $ao;
            $bi = $bo;
        }

        // universelle Gaskonstante in J/(kmol*K)
        $rg = 8314.3;
        
        // Molekulargewicht des Wasserdampfes in kg
        $m = 18.016;
        
        // Umrechnung in Kelvin
        $ko = $to + 273.15;
        $ki = $ti + 273.15;
        
        // Berechnung Sättigung Dampfdruck in hPa
        $si = 6.1078 * pow(10, (($ai * $ti) / ($bi + $ti)));
        
        // Dampfdruck in hPa
        $di = ($hi / 100) * $si;
    
        // Berechnung Taupunkt Innen
        $vi = log10($di / 6.1078);
        $dpi = $bi * $vi / ($ai - $vi);
        
        // Speichern Taupunkt?
        $update = $this->ReadPropertyBoolean('CreateDewPoint');
        if ($update == true) {
            $this->SetValue('DewPointIndoor', $dpi);
        }
        
        // Berechnung Wassergehalt Innen
        $wci = pow(10, 5) * $m / $rg * $di / $ki;
        
        // Speichern Wassergehalt?
        $update = $this->ReadPropertyBoolean('CreateWaterContent');
        if ($update == true) {
            $this->SetValue('WaterContentIndoor', $wci);
        }
        
        // Result (diff out / in)
        $wc = $wco - $wci;
        $wcy = ($wci / $wco) * 100;
        $difference = round(($wcy - 100) * 100) / 100;
        if ($wc >= 0) {
            $difference = round((100 - $wcy) * 100) / 100;
            $result = 'Lüften führt nicht zur Trocknung der Innenraumluft.';
            $hint = false;
        } elseif ($wcy <= 110) {
            $result = 'Zwar ist es innen etwas feuchter, aber es lohnt nicht zu lüften!';
            $hint = false;
        } else {
            $result = 'Lüften führt zur Trocknung der Innenraumluft!';
            $hint = true;
        }
        $this->SetValue('Result', $result);
        $this->SetValue('Hint', $hint);
        $this->SetValue('Difference', $difference);
        
        // Berechnung TF-70
        $v2 =log10 (((($hi/100.0) * $si)/(6.1078*0.7)));
        $td2 =($bo*$v2) / ($ao-$v2);
        $TF_70 =($td2*100+0.5) / 100;
        
        $update = $this->ReadPropertyBoolean('CreateTF70');
        if ($update == true) {
            $this->SetValue('TF70', $TF_70);
        }
        
        // Berechnung TF-80
        $v2 = log10 (((($hi/100.0) * $si)/(6.1078*0.8)));
        $td2 =($bo*$v2) / ($ao-$v2);
        $TF_80 =($td2*100+0.5) / 100;
       
        $update = $this->ReadPropertyBoolean('CreateTF80');
        if ($update == true) {
            $this->SetValue('TF80', $TF_80); 
        }
        
        //  Berechnung AW-Wert
        $tdw = $this->ReadPropertyFloat('TempDiffWallIndoor');
        
        $si2 = 6.1078 * pow(10.0, ( ($ao*($ti-$tdw)) / ($bo+($ti-$tdw)) ) );
        $aw = ($di/$si2);
        
        $update = $this->ReadPropertyBoolean('CreateAWValue');
        if ($update == true) {
            $this->SetValue('AWValue', $aw);
        }
        
        // Berechnung Schimmelgefahr       
        if ($aw <= 0.7) {
            $update = $this->ReadPropertyBoolean('CreateMould');
            if ($update == true) {
                $this->SetValue('Mould', 0);
            }
        } elseif (($aw > 0.7) and ($aw < 0.8)) {
            $update = $this->ReadPropertyBoolean('CreateMould');
            if ($update == true) {
                $this->SetValue('Mould', 1);
            }
        } elseif ($aw > 0.8) {
            $update = $this->ReadPropertyBoolean('CreateMould');
            if ($update == true) {
                $this->SetValue('Mould', 2);
            }
        }
        
        // Gelüftet
        $wv = GetValue($this->ReadPropertyInteger('WindowValue'));
        $dl = $this->ReadPropertyInteger('DiffLimit');
        $tts = $this->ReadPropertyBoolean('TTSAlexa');
        $nr = $this->ReadPropertyString('NameRoom');
        $AID = $this->ReadPropertyString('AlexaID');   
        $AV = $this->ReadPropertyInteger('AlexaVolume'); 
	    
        if (($wv == true) and ($difference <= $dl)){
            $update = $this->ReadPropertyBoolean('CreateAir');
            if ($update == true) {
                $this->SetValue('Ventilate', 1);
		
		//TTS Alexa Echo Remote Modul   
                if ($tts == true){
                    EchoRemote_SetVolume($AID, $AV);
		    EchoRemote_TextToSpeech($AID, "Lüften $nr benenden");   
                }
            } 
        } 
      }

    /**
     * This function will be available automatically after the module is imported with the module control.
     * Using the custom prefix this function will be callable from PHP and JSON-RPC through:.
     *
     * SCHB_Duration($id, $duration);
     *
     * @param int $duration Wartezeit einstellen.
     */
    public function Duration(int $duration)
    {
        IPS_SetProperty($this->InstanceID, 'UpdateTimer', $duration);
        IPS_ApplyChanges($this->InstanceID);
    }
}
