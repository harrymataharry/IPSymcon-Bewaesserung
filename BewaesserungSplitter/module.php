<?php

class BewässerungHauptmodul extends IPSModule
{
    public function Create()
    {
        parent::Create();
        $this->RegisterPropertyInteger('GlobalActiveID', 0);
        $this->RegisterPropertyInteger('PowerEnergyID', 0);
        $this->RegisterPropertyInteger('PowerStateID', 0);
        $this->RegisterPropertyInteger('ArchiveID', 0);
        $this->RegisterPropertyInteger('RainVariableID', 0);
        $this->RegisterPropertyInteger('RainThreshold', 10);
        $this->RegisterPropertyInteger('AlarmStatusID', 0);
        $this->RegisterPropertyInteger('AlarmTextID', 0);

        $this->RegisterTimer('DailyStartTimer', 0, 'BEW_StartCycle($_IPS[\'TARGET\']);');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();
    }

    public function StartCycle()
    {
        $this->SendDebug('StartCycle', 'Manueller oder geplanter Start des Bewässerungszyklus', 0);

        $globalActiveID = $this->ReadPropertyInteger('GlobalActiveID');
        if ($globalActiveID > 0 && !GetValue($globalActiveID)) {
            $this->SendDebug('StartCycle', 'Bewässerung ist global deaktiviert. Abbruch.', 0);
            return;
        }

        $powerEnergyID = $this->ReadPropertyInteger('PowerEnergyID');
        if ($powerEnergyID > 0) {
            $lastUpdate = IPS_GetVariable($powerEnergyID)['VariableChanged'];
            if (time() - $lastUpdate > 600) { 
                $this->SetAlarm('Stromversorgung scheint inaktiv (keine Zähler-Aktualisierung).');
                return;
            }
        }
        
        $powerStateID = $this->ReadPropertyInteger('PowerStateID');
        if ($powerStateID > 0 && !GetValue($powerStateID)) {
            $this->SetAlarm('Stromversorgung ist laut Status-Variable ausgeschaltet.');
            return;
        }
        $this->SendDebug('StartCycle', 'Stromversorgung OK.', 0);

        $archiveID = $this->ReadPropertyInteger('ArchiveID');
        $rainVarID = $this->ReadPropertyInteger('RainVariableID');
        if ($archiveID > 0 && $rainVarID > 0) {
            $values = AC_GetAggregatedValues($archiveID, $rainVarID, 1, strtotime('-72 hours'), time(), 0);
            $totalRain = empty($values) ? 0 : $values[0]['Avg'];
            $rainThreshold = $this->ReadPropertyInteger('RainThreshold');

            $this->SendDebug('StartCycle', "Regenmenge 72h: $totalRain L, Schwelle: $rainThreshold L", 0);
            if ($totalRain >= $rainThreshold) {
                $this->SendDebug('StartCycle', 'Genug Regen in den letzten 72h. Bewässerung wird übersprungen.', 0);
                return;
            }
        }

        $this->SendDebug('StartCycle', 'Alle globalen Prüfungen bestanden. Starte Bewässerung der Flächen.', 0);
        $this->ResetAlarm();

        $childrenIDs = IPS_GetChildrenIDs($this->InstanceID);
        foreach ($childrenIDs as $childID) {
            if (IPS_GetInstance($childID)['InstanceStatus'] == 102) {
                $this->SendDebug('StartCycle', "Starte Bewässerung für Fläche $childID.", 0);
                try {
                    BEWArea_StartWatering($childID);
                } catch (Exception $e) {
                    $this->SendDebug('StartCycle', "Fehler beim Starten der Fläche $childID: " . $e->getMessage(), 0);
                }
                IPS_Sleep(5000);
            }
        }
    }
    
    public function SetAlarm(string $text) {
        $this->SendDebug('SetAlarm', "Alarm ausgelöst: $text", 0);
        $statusID = $this->ReadPropertyInteger('AlarmStatusID');
        $textID = $this->ReadPropertyInteger('AlarmTextID');
        if ($statusID > 0) SetValue($statusID, true);
        if ($textID > 0) SetValue($textID, $text);
    }
    
    private function ResetAlarm() {
        $statusID = $this->ReadPropertyInteger('AlarmStatusID');
        if ($statusID > 0) SetValue($statusID, false);
    }
}
