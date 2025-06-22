<?php
class IrrigationConfig extends IPSModule
{
    public function Create()
    {
        parent::Create();
    }

    public function GetConfigurationForm()
    {
        return json_encode([
            "elements" => [],
            "actions" => [],
            "status" => []
        ]);
    }
}
?>
