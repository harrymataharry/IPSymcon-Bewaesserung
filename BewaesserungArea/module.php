<?php

class BewaesserungArea extends IPSModule
{
    public function Create()
    {
        parent::Create();
        $this->RegisterPropertyString('AreaName', '');
        $this->RegisterPropertyInteger('VentilID', 0);
        $this->RegisterPropertyInteger('ZielWassermenge', 100);
        $this->RegisterPropertyInteger('MaxDuration', 30);
        $this->RegisterPropertyInteger('BodenfeuchteSensorID', 0);
        $this->RegisterPropertyInteger('MindestBodenfeuchte', 50);
        $this->RegisterPropertyInteger('ShellyReachableID', 0);

        $this->RegisterTimer('WateringTimer', 0, 'BEWArea_ProcessWatering($_IPS[\'TARGET\']);');
        $this->RegisterVariableBoolean('Active', 'Bewässerung', '~Switch', 1);
        $this->EnableAction('Active');
        $this->RegisterVariableString('Status', 'Status', '', 2);
        $this->RegisterVariableFloat('LastConsumption', 'Letzter Verbrauch', '~Water_Litre', 3);
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();
        $this->SetStatus(102);
    }
    
    public function RequestAction($Ident, $Value)
    {
        if ($Ident == 'Active') {
            if ($Value) {
                $this->StartWatering();
            } else {
                $this->StopWatering('Manuell gestoppt');
            }
        }
    }

    public function StartWatering()
    {
        $this->SendDebug('StartWatering', 'Anfrage zum Start der Bewässerung erhalten', 0);
        $areaName = $this->ReadPropertyString('AreaName');

        $bodenfeuchteID = $this->ReadPropertyInteger('BodenfeuchteSensorID');
        if ($bodenfeuchteID > 0) {
            $aktuelleFeuchte = GetValue($bodenfeuchteID);
            $schwelle = $this->ReadPropertyInteger('MindestBodenfeuchte');
            if ($aktuelleFeuchte >= $schwelle) {
                $this->SendDebug('StartWatering', "Bodenfeuchte ($aktuelleFeuchte%) ist ausreichend. Abbruch.", 0);
                return;
            }
        }

        $shellyReachableID = $this->ReadPropertyInteger('ShellyReachableID');
        if ($shellyReachableID > 0 && !GetValue($shellyReachableID)) {
            $this->SetErrorState("Fehler bei '$areaName': Shelly ist nicht erreichbar.");
            return;
        }

        $this->SetStatus(200);
        $this->SetValue('Status', 'Starte...');
        $this->SetValue('Active', true);

        $ventilID = $this->ReadPropertyInteger('VentilID');
        if ($ventilID > 0) RequestAction($ventilID, true);
        IPS_Sleep(2000);

        $parentID = IPS_GetInstance($this->InstanceID)['ConnectionID'];
        if ($parentID == 0 || !IPS_ObjectExists($parentID)) {
            $this->SetErrorState("Fehler bei '$areaName': Kein Hauptmodul als Gateway verbunden.");
            $this->StopWatering("Fehler: Kein Gateway");
            return;
        }
        $ioID = IPS_GetInstance($parentID)['ConnectionID'];
        if ($ioID == 0 || !IPS_ObjectExists($ioID)) {
             $this->SetErrorState("Fehler bei '$areaName': Kein I/O (Wasserzähler) am Hauptmodul konfiguriert.");
             $this->StopWatering("Fehler: Kein IO");
             return;
        }
        
        $this->SetBuffer('State', 'watering');
        $this->SetBuffer('StartTime', time());
        $this->SetBuffer('StartWater', GetValue($ioID));

        $this->SetTimerInterval('WateringTimer', 30000);
        $this->ProcessWatering();
    }
    
    public function StopWatering(string $message)
    {
        $this->SendDebug('StopWatering', "Bewässerung wird gestoppt: $message", 0);
        $this->SetTimerInterval('WateringTimer', 0);

        $ventilID = $this->ReadPropertyInteger('VentilID');
        if ($ventilID > 0) RequestAction($ventilID, false);
        
        $parentID = IPS_GetInstance($this->InstanceID)['ConnectionID'];
        if($parentID > 0 && IPS_GetInstance($parentID)['ConnectionID'] > 0) {
            $ioID = IPS_GetInstance($parentID)['ConnectionID'];
            $startWater = json_decode($this->GetBuffer('StartWater'));
            $endWater = GetValue($ioID);
            $verbrauch = $endWater - $startWater;
            $this->SetValue('LastConsumption', $verbrauch);
        }

        $this->SetValue('Active', false);
        $this->SetValue('Status', $message);
        $this->SetBuffer('State', 'idle');
        
        if($this->GetStatus() != 201) {
            $this->SetStatus(102);
        }
    }

    public function ProcessWatering()
    {
        if ($this->GetBuffer('State') !== 'watering') return;
        
        $startTime = $this->GetBuffer('StartTime');
        $startWater = $this->GetBuffer('StartWater');
        $zielMenge = $this->ReadPropertyInteger('ZielWassermenge');
        $maxDuration = $this->ReadPropertyInteger('MaxDuration') * 60;

        $parentID = IPS_GetInstance($this->InstanceID)['ConnectionID'];
        $ioID = IPS_GetInstance($parentID)['ConnectionID'];
        $aktuellerWater = GetValue($ioID);
        $verbrauch = $aktuellerWater - $startWater;
        
        $this->SetValue('Status', "Verbrauch: " . round($verbrauch, 2) . " / $zielMenge L");

        if ($verbrauch >= $zielMenge) {
            $this->StopWatering("Zielmenge erreicht (" . round($verbrauch, 2) . " L)");
            return;
        }
        if (time() - $startTime > $maxDuration) {
            $this->SetErrorState("Fehler: Maximale Dauer von " . ($maxDuration/60) . " min überschritten.");
            $this->StopWatering("Fehler: Zeitlimit");
            return;
        }
    }

    private function SetErrorState(string $message)
    {
        $this->SetStatus(201);
        $parentID = IPS_GetInstance($this->InstanceID)['ConnectionID'];
        if ($parentID > 0) {
            BEW_SetAlarm($parentID, $message);
        }
    }
}
