<?php

declare(strict_types=1);

class Alerting extends IPSModule
{
    public function Create()
    {
        //Never delete this line!
        parent::Create();

        //Properties
        $this->RegisterPropertyString('Sensors', '[]');
        $this->RegisterPropertyString('Targets', '[]');
        $this->RegisterPropertyInteger('ActivateDelay', 10);
        $this->RegisterPropertyBoolean('NightAlarm', false);
        $this->RegisterPropertyInteger('TriggerDelay', 0);

        //Variables
        $this->RegisterVariableBoolean('Active', $this->Translate('Active'), '~Switch', 10);
        $this->EnableAction('Active');
        $this->RegisterVariableString('DelayDisplay', $this->Translate('Time to Activation'), '', 20);
        $this->RegisterVariableBoolean('Alert', $this->Translate('Alert'), '~Alert', 30);
        $this->EnableAction('Alert');
        $this->RegisterVariableString('ActiveSensors', $this->Translate('Active Sensors'), '~TextBox', 40);

        //Attributes
        $this->RegisterAttributeInteger('LastAlert', 0);

        //Timer
        $this->RegisterTimer('Delay', 0, 'ARM_Activate($_IPS[\'TARGET\']);');
        $this->RegisterTimer('TriggerDelay', 0, 'ARM_UpdateDelayedAlarm($_IPS[\'TARGET\']);');
    }

    public function ApplyChanges()
    {
        //Never delete this line!
        parent::ApplyChanges();

        $sensors = json_decode($this->ReadPropertyString('Sensors'));
        $targets = json_decode($this->ReadPropertyString('Targets'));

        //Update active sensors
        $this->updateActive();

        //DelayDisplay
        $this->SetBuffer('Active', json_encode($this->GetValue('Active')));
        $this->stopDelay();

        $nightAlarm = $this->ReadPropertyBoolean('NightAlarm');
        $presentation = $nightAlarm ? ['PRESENTATION' => VARIABLE_PRESENTATION_ENUMERATION, 'OPTIONS' => json_encode([
            ['Caption' => $this->Translate('Stay'), 'Value' => 0, 'IconActive' => false, 'Color' => -1],
            ['Caption' => $this->Translate('Away'), 'Value' => 1, 'IconActive' => false, 'Color' => -1],
            ['Caption' => $this->Translate('Night'), 'Value' => 2, 'IconActive' => false, 'Color' => -1],
            ['Caption' => $this->Translate('Disarm'), 'Value' => 3, 'IconActive' => false, 'Color' => -1]
        ])] : ['PRESENTATION' => VARIABLE_PRESENTATION_SWITCH];
        $this->MaintainVariable('Active', $this->Translate('Active'), $nightAlarm ? VARIABLETYPE_INTEGER : VARIABLETYPE_BOOLEAN, $presentation, 0, true);
        $this->MaintainVariable('DelayDisplay', $this->Translate('Time to Activation'), VARIABLETYPE_INTEGER, ['PRESENTATION' => VARIABLE_PRESENTATION_DURATION, 'COUNTDOWN_TYPE' => 1 /* Until value in variable */], 0, true);
        $this->EnableAction('Active');

        //Deleting all References
        foreach ($this->GetReferenceList() as $referenceID) {
            $this->UnregisterReference($referenceID);
        }

        //Adding references for targets
        foreach ($targets as $target) {
            $this->RegisterReference($target->VariableID);
        }
        foreach ($sensors as $sensor) {
            $this->RegisterMessage($sensor->ID, VM_UPDATE);
            $this->RegisterReference($sensor->ID);
        }
    }

    public function Migrate($JSONData)
    {
        // Never delete this line!
        parent::Migrate($JSONData);

        $original = json_decode($JSONData, true);
        $config = $original['configuration'];
        // Sensors
        $sensors = json_decode($config['Sensors'], true);
        if (!empty($sensors) && !isset($sensors[0]['NightAlarm'])) {
            $newSensors = [];
            foreach ($sensors as $sensor) {
                $newSensors[] = ['ID' => $sensor['ID'], 'NightAlarm' => true];
            }
            $config['Sensors'] = json_encode($newSensors);
        }

        // Targets
        $targets = json_decode($config['Targets'], true);
        if (!empty($targets) && isset($targets[0]['ID'])) {
            $newTargets = [];
            foreach ($targets as $target) {
                $newTargets[] = ['VariableID' => $target['ID'], 'Type' => 0 /* Variable */];
            }
            $config['Targets'] = json_encode($newTargets);
        }
        $original['configuration'] = $config;

        return json_encode($original);
    }

    public function MessageSink($TimeStamp, $SenderID, $Message, $Data)
    {
        $this->SendDebug('MessageSink', 'SenderID: ' . $SenderID . ', Message: ' . $Message, 0);

        $sensors = json_decode($this->ReadPropertyString('Sensors'));
        $nightAlarm = $this->ReadPropertyBoolean('NightAlarm');
        $nightActive = $nightAlarm ? ($this->GetValue('Active') === 2 /* Night */) : false;
        foreach ($sensors as $sensor) {
            if ($sensor->ID == $SenderID) {
                if ($nightActive && !$sensor->NightAlarm) {
                    return;
                } else {
                    if ($this->ReadPropertyInteger('TriggerDelay') > 0) {
                        $this->triggerAlarmDelayed($sensor->ID, GetValue($sensor->ID));
                    } else {
                        $this->triggerAlert($sensor->ID, GetValue($sensor->ID));
                    }
                    $this->updateActive();
                    return;
                }
            }
        }
    }

    public function SetAlert(bool $Status)
    {
        $targets = json_decode($this->ReadPropertyString('Targets'));

        //Lets notify all target devices
        foreach ($targets as $target) {
            // We only want to set the variable / execute the action if the status matches
            if ($target->Type === 0) {
                //only allow links
                if (IPS_VariableExists($target->VariableID)) {
                    $v = IPS_GetVariable($target->VariableID);
                    $profileName = $this->GetProfileName($v);

                    //If we somehow do not have a profile take care that we do not fail immediately
                    $isReversed = $this->profileInverted($target->VariableID);
                    if ($profileName != '') {
                        $variableProfile = IPS_GetVariableProfile($profileName);
                        //If we are enabling analog devices we want to switch to the maximum value (e.g. 100%)
                        if ($Status != $isReversed) {
                            $actionValue = $variableProfile['MaxValue'];
                        } else {
                            $actionValue = $variableProfile['MinValue'];
                        }
                        //Reduce to boolean if required

                        if ($v['VariableType'] == 0) {
                            $actionValue = $isReversed ? !$Status : $Status;
                        }
                    } else {
                        $actionValue = $Status;
                    }
                    RequestAction($target->VariableID, $actionValue);
                }
            } else {
                if ($target->AlarmStatus !== $Status) {
                    continue;
                }
                $action = json_decode($target->Action, true);
                IPS_RunAction($action['actionID'], $action['parameters']);
            }
        }

        SetValue($this->GetIDForIdent('Alert'), $Status);
    }

    public function GetLastAlertID()
    {
        return $this->ReadAttributeInteger('LastAlert');
    }

    public function ConvertToNewVersion()
    {
        if (@IPS_GetObjectIDByIdent('Sensors', $this->InstanceID) !== false) {
            IPS_SetProperty($this->InstanceID, 'Sensors', json_encode($this->convertLinksToTargetIDArray('Sensors')));
        }

        if (@IPS_GetObjectIDByIdent('Targets', $this->InstanceID) !== false) {
            IPS_SetProperty($this->InstanceID, 'Targets', json_encode($this->convertLinksToTargetIDArray('Targets')));
        }

        IPS_ApplyChanges($this->InstanceID);

        echo $this->Translate('Converting successful! Please reopen this configuration page. If everything is correct, all events and categories of this instance can be deleted');
    }

    public function SetActive(bool $Value)
    {
        if (!$Value) {
            $this->SetBuffer('Active', json_encode(false));
            $this->SetAlert(false);
            $this->stopDelay();
            return;
        }

        //Start activation process only if not already active
        if (!json_decode($this->GetBuffer('Active'))) {

            //Only start with delay when delay is > 0
            if ($this->ReadPropertyInteger('ActivateDelay') > 0) {
                $this->startDelay();
            } else {
                $this->SetBuffer('Active', json_encode(true));
            }
        }
    }

    public function RequestAction($Ident, $Value)
    {
        switch ($Ident) {
            case 'Active':
                if ($this->ReadPropertyBoolean('NightAlarm')) {
                    $this->SetActive(in_array($Value, [1/* Away */, 2 /* Night */]));
                    SetValue($this->GetIDForIdent('Active'), $Value);
                } else {
                    SetValue($this->GetIDForIdent('Active'), $Value);
                    $this->SetActive($Value);
                }
                break;
            case 'Alert':
                $this->SetAlert($Value);
                break;
            default:
                throw new Exception('Invalid ident');
        }
    }

    public function triggerAlarmDelayed($variableID, $value)
    {
        //Only enable alarming if our module is active
        if (!json_decode($this->GetBuffer('Active'))) {
            return;
        }

        
        $bufferString = $this->GetBuffer('TriggeredVariables');
        $triggeredVariables = empty($bufferString) ? [] : json_decode($bufferString);
        if ($this->getAlertValue($variableID, $value)) {
            if (!in_array($variableID, $triggeredVariables)) {
                $triggeredVariables[] = $variableID;
            }
            $this->SetBuffer('TriggeredVariables', json_encode($triggeredVariables));
            $delay = $this->ReadPropertyInteger('TriggerDelay') * 1000;
            $this->SetTimerInterval('TriggerDelay', $delay);
        } else {
            if (!in_array($variableID, $triggeredVariables)) {
                $triggeredVariables[] = $variableID;
            }
            $this->SetBuffer('TriggeredVariables', json_encode($triggeredVariables));
        }
    }

    public function UpdateDelayedAlarm()
    {
        $bufferString = $this->GetBuffer('TriggeredVariables');
        $triggeredVariables = empty($bufferString) ? [] : json_decode($bufferString);
        if (!empty($triggeredVariables)) {
            $this->triggerAlert($triggeredVariables[0], GetValue($triggeredVariables[0]));
            // We may want to only remove the variable that triggered the alarm. So the we would have staggered alarms
            $triggeredVariables = [];
            $this->SetBuffer('TriggeredVariables', json_encode($triggeredVariables));
            $this->SetTimerInterval('TriggerDelay', 0);
        }
    }

    public function Activate()
    {
        $this->SetBuffer('Active', json_encode(true));
        $this->stopDelay();
    }

    public function GetConfigurationForm()
    {
        if ($this->ReadPropertyString('Sensors') == '[]' && $this->ReadPropertyString('Targets') == '[]') {
            $sensorsID = @IPS_GetObjectIDByIdent('Sensors', $this->InstanceID);
            $targetsID = @IPS_GetObjectIDByIdent('Targets', $this->InstanceID);
            if ($sensorsID !== false || $targetsID !== false) {
                if (IPS_CategoryExists($sensorsID) || IPS_CategoryExists($targetsID)) {
                    if (IPS_HasChildren($sensorsID) || IPS_HasChildren($targetsID)) {
                        $formconvert = [];
                        $formconvert['elements'][] = ['type' => 'Button', 'label' => $this->Translate('Convert'), 'onClick' => 'ARM_ConvertToNewVersion($id)'];
                        return json_encode($formconvert);
                    }
                }
            }
        }

        $formdata = json_decode(file_get_contents(__DIR__ . '/form.json'));

        // Hide night alarm column
        $nightAlarm = $this->ReadPropertyBoolean('NightAlarm');
        $formdata->elements[2]->columns[2]->visible = $nightAlarm;
        $formdata->elements[2]->columns[2]->edit->visible = $nightAlarm;

        //Annotate existing elements
        $sensors = json_decode($this->ReadPropertyString('Sensors'));
        foreach ($sensors as $sensor) {
            //We only need to add annotations. Remaining data is merged from persistence automatically.
            //Order is determinted by the order of array elements
            if (IPS_ObjectExists($sensor->ID) && $sensor->ID !== 0) {
                $status = 'OK';
                $rowColor = '';
                if (!IPS_VariableExists($sensor->ID)) {
                    $status = $this->Translate('Not a variable');
                    $rowColor = '#FFC0C0';
                }

                $formdata->elements[2]->values[] = [
                    'Status' => $status,
                ];
            } else {
                $formdata->elements[2]->values[] = [
                    'rowColor' => '#FFC0C0',
                ];
            }
        }

        //Annotate existing elements
        $targets = json_decode($this->ReadPropertyString('Targets'));
        foreach ($targets as $target) {
            $status = 'OK';
            $rowColor = '';
            $name = '';
            if (isset($target->Type) && $target->Type === 0 /* Variable */) {
                //We only need to add annotations. Remaining data is merged from persistence automatically.
                //Order is determined by the order of array elements
                if (IPS_ObjectExists($target->VariableID) && $target->VariableID !== 0) {
                    if (!IPS_VariableExists($target->VariableID)) {
                        $status = $this->Translate('Not a variable');
                        $rowColor = '#FFC0C0';
                    } elseif (!HasAction($target->VariableID)) {
                        $status = $this->Translate('No action set');
                        $rowColor = '#FFC0C0';
                    }
                    $name = IPS_GetName($target->VariableID);
                } else {
                    $status = $this->Translate('Not found!');
                    $rowColor = '#FFC0C0';
                }

            } elseif (isset($target->Action)) {
                $actionDetails = [];
                $action = json_decode($target->Action);
                if (function_exists('IPS_GetAction')) {
                    $actionDetails = json_decode(IPS_GetAction($action->actionID));
                    $name = $actionDetails->caption;
                } else {
                    $actions = json_decode(IPS_GetActions());
                    foreach ($actions as $entry) {
                        if ($entry->id == $action->actionID) {
                            $language = IPS_GetSystemLanguage();
                            $caption = $entry->caption;
                            if (isset($entry->locale->$language)) {
                                $name = $entry->locale->$caption;
                            } else {
                                $lang = explode('_', $language)[0];
                                if (isset($entry->locale->$lang)) {
                                    $name = $entry->locale->$lang->$caption;
                                }

                            }
                            break;
                        }
                    }
                }
                if (isset($action->parameters->TARGET) && IPS_ObjectExists($action->parameters->TARGET)) {
                    $name .= ' (' . IPS_GetName($action->parameters->TARGET) . ')';
                }
            }
            $formdata->elements[5]->values[] = [
                'Name'      => $name,
                'Status'    => $status,
                'rowColor'  => $rowColor,
                'ExecuteOn' => (!isset($target->Type) || $target->Type === 0 /* Variable */) ? $this->Translate('Alert/OK') : ($target->AlarmStatus ? $this->Translate('Alert') : $this->Translate('OK'))
            ];
        }

        return json_encode($formdata);
    }

    public function UIUpdateList(int $Value)
    {
        $this->UpdateFormField('VariableID', 'visible', $Value === 0);
        $this->UpdateFormField('Action', 'visible', $Value === 1);
        $this->UpdateFormField('AlarmStatus', 'visible', $Value === 1);
    }

    public function UIGetTargetForm(IPSList $Values)
    {
        $type = $Values['Type'] ?? 0;
        return [
            [
                'type' => 'RowLayout', 'items' => [
                    [
                        'type'     => 'Select',
                        'caption'  => $this->Translate('Type'),
                        'name'     => 'Type',
                        'onChange' => 'ARM_UIUpdateList($id, $Type);',
                        'options'  => [
                            ['caption' => 'Variable', 'value' => 0],
                            ['caption' => 'Action', 'value' => 1],
                        ],
                        'save' => true,
                    ],
                    [
                        'type'    => 'Select',
                        'caption' => $this->Translate('Execute on'),
                        'name'    => 'AlarmStatus',
                        'visible' => $type === 1, // Action
                        'options' => [
                            ['caption' => 'Alarm', 'value' => true],
                            ['caption' => 'OK', 'value' => false],
                        ]
                    ],
                ]
            ],
            [
                'type'    => 'SelectVariable',
                'caption' => $this->Translate('Variable'),
                'name'    => 'VariableID',
                'visible' => $type === 0, // Variable
            ],
            [
                'type'    => 'SelectAction',
                'caption' => 'Action',
                'name'    => 'Action',
                'visible' => $type === 1, // Action
            ],

        ];
    }

    public function UIUpdateNightAlarm(bool $NightAlarm)
    {
        $this->UpdateFormField('Sensors', 'columns.2.visible', $NightAlarm);
        $this->UpdateFormField('Sensors', 'columns.2.edit', json_encode(['type' => 'CheckBox', 'visible' => $NightAlarm]));
    }

    private function triggerAlert($sourceID, $sourceValue)
    {

        //Only enable alarming if our module is active
        if (!json_decode($this->GetBuffer('Active'))) {
            return;
        }

        if ($this->getAlertValue($sourceID, $sourceValue)) {
            $this->WriteAttributeInteger('LastAlert', $sourceID);
            $this->SetAlert(true);
        }
    }

    private function convertLinksToTargetIDArray($CategoryIdent)
    {
        $linkArray = [];
        $linkIDs = IPS_GetChildrenIDs(@IPS_GetObjectIDByIdent($CategoryIdent, $this->InstanceID));
        foreach ($linkIDs as $linkID) {
            if (IPS_LinkExists($linkID)) {
                $linkArray[] = ['ID' => IPS_GetLink($linkID)['TargetID']];
            }
        }

        return $linkArray;
    }

    private function GetProfileName($Variable)
    {
        if ($Variable['VariableCustomProfile'] != '') {
            return $Variable['VariableCustomProfile'];
        } else {
            return $Variable['VariableProfile'];
        }
    }

    private function profileInverted($VariableID)
    {
        return substr($this->GetProfileName(IPS_GetVariable($VariableID)), -strlen('.Reversed')) === '.Reversed';
    }

    private function getAlertValue($variableID, $value)
    {
        switch ($this->GetProfileName(IPS_GetVariable($variableID))) {
            case '~Window.Hoppe':
                return ($value == 0) || ($value == 2);

            case '~Window.HM':
                return ($value == 1) || ($value == 2);

            default:
                if ($this->profileInverted($variableID)) {
                    return !boolval($value);
                } else {
                    return boolval($value);
                }
        }
    }

    private function updateActive()
    {
        $sensors = json_decode($this->ReadPropertyString('Sensors'), true);

        $activeSensors = '';
        $nightAlarm = $this->GetValue('Active') === 2 /* Night */;
        foreach ($sensors as $sensor) {
            $sensorID = $sensor['ID'];
            if ($nightAlarm && !$sensor['NightAlarm']) {
                continue;
            }
            if ($this->getAlertValue($sensorID, GetValue($sensorID))) {
                $activeSensors .= '- ' . IPS_GetLocation($sensorID) . "\n";
            }
        }
        if ($activeSensors == '') {
            IPS_SetHidden($this->GetIDForIdent('ActiveSensors'), true);
            return;
        }

        $this->SetValue('ActiveSensors', $activeSensors);
        IPS_SetHidden($this->GetIDForIdent('ActiveSensors'), false);
    }

    private function startDelay()
    {
        //Unhide countdown
        IPS_SetHidden($this->GetIDForIdent('DelayDisplay'), false);
        $this->SetValue('DelayDisplay', time() + $this->ReadPropertyInteger('ActivateDelay'));

        //Start timer for activation
        $this->SetTimerInterval('Delay', $this->ReadPropertyInteger('ActivateDelay') * 1000);
    }

    private function stopDelay()
    {
        $this->SetTimerInterval('Delay', 0);
        $this->SetValue('DelayDisplay', 0);
        IPS_SetHidden($this->GetIDForIdent('DelayDisplay'), true);
    }
}
