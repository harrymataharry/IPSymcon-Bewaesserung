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
        // NEU: Neue Eigenschaft für die Stunden registrieren
        $this->RegisterPropertyInteger('LookbackHours', 72);
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

        // Prüfung 1: Globaler Schalter
        $globalActiveID = $this->ReadPropertyInteger('GlobalActiveID');
        if ($globalActiveID > 0 && !GetValue($globalActiveID)) {
            $this->SendDebug('StartCycle', 'Bewässerung ist global deaktiviert. Abbruch.', 0);
            return;
        }

        // Prüfung 2: Stromversorgung
        $powerEnergyID = $this->ReadPropertyInteger('PowerEnergyID');
        if ($powerEnergyID > 0) {
            $lastUpdate = IPS_GetVariable($powerEnergyID)['VariableChanged'];
            if (time() - $lastUpdate > 900) { // 15 Minuten Toleranz
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

        // +++ BEGINN DER LOGIK-ÄNDERUNG +++
        // Prüfung 3: Regenmenge der letzten X Stunden (Logik aus Skript übernommen)
        $archiveID = $this->ReadPropertyInteger('ArchiveID');
        $rainVarID = $this->ReadPropertyInteger('RainVariableID');
        $lookbackHours = $this->ReadPropertyInteger('LookbackHours');

        if ($archiveID > 0 && $rainVarID > 0 && $lookbackHours > 0) {
            $now = time();
            $startTime = $now - ($lookbackHours * 3600); // 3600 Sekunden pro Stunde

            // Rohdaten aus dem Archiv laden, genau wie im Skript
            $loggedValues = AC_GetLoggedValues($archiveID, $rainVarID, $startTime, $now, 0);

            $maxRainValue = 0;
            if (is_array($loggedValues) && count($loggedValues) > 0) {
                // Maximalen Wert im Zeitraum finden
                foreach ($loggedValues as $record) {
                    if ($record['Value'] > $maxRainValue) {
                        $maxRainValue = $record['Value'];
                    }
                }
            }

            $rainLastXHours = round($maxRainValue, 2);
            $rainThreshold = $this->ReadPropertyInteger('RainThreshold');

            $this->SendDebug('StartCycle', "Regenmenge letzte $lookbackHours Stunden: $rainLastXHours L, Schwelle: $rainThreshold L", 0);

            if ($rainLastXHours >= $rainThreshold) {
                $this->SendDebug('StartCycle', "Genug Regen in den letzten $lookbackHours Stunden. Bewässerung wird übersprungen.", 0);
                return;
            }
        }
        // +++ ENDE DER LOGIK-ÄNDERUNG +++

        $this->SendDebug('StartCycle', 'Alle globalen Prüfungen bestanden. Starte Bewässerung der Flächen.', 0);
        $this->ResetAlarm();

        // Prüfung 4: Flächen starten
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
