<?php

class Bewaesserung extends IPSModule {
    public function Create() {
        parent::Create();
        $this->RegisterPropertyInteger('global', 11708);
        // Weitere Properties…
    }
    public function ApplyChanges() {
        parent::ApplyChanges();
        // Konfig beim Speichern
    }
    public function RunScript() {
        // Die Logik deines Hauptskripts
    }
}
?>
