<?php

	declare(strict_types=1);
	
	include __DIR__ . "/../libs/traits.php";
	
		class FordpassVehicle extends IPSModule {
			use Profiles;
			use Utility;
			use Lock;
	
			public function Create(){
				//Never delete this line!
				parent::Create();
	
				$this->ConnectParent('{4651EC8E-D0BB-1354-1167-BB7C87729F19}');

				$this->RegisterProfileBoolean('FPV.SecuriAlert', 'Alert', '', '');
				$this->RegisterProfileFloat('FPV.Odometer', 'Information', '', ' km');
				$this->RegisterProfileFloat('FPV.BatteryFillLevel', 'Electricity', '', ' %');
				$this->RegisterProfileFloat('FPV.12VBatterySOC', 'Battery', '', ' V');
				$this->RegisterProfileBooleanEx('FPV.Status', 'Information', '', '', [
					[true, 'OK', 'Ok', -1],
					[false, 'Check condition', 'Warning', -1]
				]);
				$this->RegisterProfileBooleanEx('FPV.IgnitionStatus', 'Key', '', '', [
					[true, 'Running', '', -1],
					[false, 'Stopped', '', -1]
				]);
				$this->RegisterProfileBooleanEx('FPV.DoorStatus', 'Information', '', '', [
					[true, 'Closed', '', -1],
					[false, 'Open', '', -1]
				]);
				$this->RegisterProfileBooleanEx('FPV.WindowStatus', 'Information', '', '', [
					[true, 'Closed', '', -1],
					[false, 'Open', '', -1]
				]);
				$this->RegisterProfileBooleanEx('FPV.AlarmStatus', 'Alert', '', '', [
					[true, 'Activated', '', -1],
					[false, 'Deactivated', '', -1]
				]);

				$this->RegisterPropertyInteger('UpdateInterval', 1);
				$this->RegisterPropertyInteger('ForceInterval', 6);
				//$this->RegisterPropertyInteger('ForceIntervalDisconnected', 15);
				$this->RegisterPropertyString('VIN', '');
					
				$this->RegisterVariableBoolean('Start', 'Start', '~Switch', 1);
				$this->EnableAction('Start');

				$this->RegisterVariableBoolean('Lock', 'Lock', '~Lock', 2);
				$this->EnableAction('Lock');

				$this->RegisterVariableBoolean('Guard', 'SecuriAlert', 'FPV.SecuriAlert', 3);
				$this->EnableAction('Guard');
				
				$this->RegisterVariableBoolean('Alarm', 'Alarm', 'FPV.AlarmStatus', 4);
				$this->RegisterVariableFloat('Odometer', 'Total distance', 'FPV.Odometer', 5);
				$this->RegisterVariableFloat('BatteryFillLevel', 'Battery Fill Level', 'FPV.BatteryFillLevel', 6);
				$this->RegisterVariableFloat('12VBatterySOC', '12V Battery SOC', 'FPV.12VBatterySOC', 7);
				$this->RegisterVariableBoolean('TirePressure', 'Tire Pressure', 'FPV.Status', 8);
				$this->RegisterVariableBoolean('IgnitionStatus', 'Ignition', 'FPV.IgnitionStatus', 9);
				$this->RegisterVariableBoolean('DoorStatus', 'Doors Status', 'FPV.DoorStatus', 10);
				$this->RegisterVariableBoolean('WindowStatus', 'Windows Status', 'FPV.WindowStatus', 11);
				
				$id = $this->RegisterVariableString('OTAInformation', 'OTA Information', '~HTMLBox', 12);
				IPS_SetIcon($id, 'Information');
				$id = $this->RegisterVariableString('OTAAlertStatus', 'OTA Alert Status', '', 13);
				IPS_SetIcon($id, 'Information');
									
				$this->RegisterTimer('FordPassRefresh' . (string)$this->InstanceID, 0, 'IPS_RequestAction(' . (string)$this->InstanceID . ', "Refresh", 0);'); 
				$this->RegisterTimer('FordPassForce' . (string)$this->InstanceID, 0, 'IPS_RequestAction(' . (string)$this->InstanceID . ', "Force", 0);'); 
	
				$this->RegisterMessage(0, IPS_KERNELMESSAGE);
			}
	
			public function Destroy(){
				$module = json_decode(file_get_contents(__DIR__ . '/module.json'));
				if(count(IPS_GetInstanceListByModuleID($module->id))==0) {
					$this->DeleteProfile('FPV.SecuriAlert');	
					$this->DeleteProfile('FPV.Odometer');
					$this->DeleteProfile('FPV.BatteryFillLevel');
					$this->DeleteProfile('FPV.Status');
					$this->DeleteProfile('FPV.IgnitionStatus');
					$this->DeleteProfile('FPV.DoorStatus');
					$this->DeleteProfile('FPV.WindowStatus');
					$this->DeleteProfile('FPV.12VBatterySOC');
					$this->DeleteProfile('FPV.AlarmStatus');
				}
	
				//Never delete this line!
				parent::Destroy();
			}
	
			public function ApplyChanges(){
				//Never delete this line!
				parent::ApplyChanges();
	
				$this->SetReceiveDataFilter('.*"ChildId":"' . (string)$this->InstanceID .'".*');
	
				if (IPS_GetKernelRunlevel() == KR_READY) {
					$this->InitTimers();
				}
			}
	
			public function MessageSink($TimeStamp, $SenderID, $Message, $Data) {
				parent::MessageSink($TimeStamp, $SenderID, $Message, $Data);
	
				if ($Message == IPS_KERNELMESSAGE && $Data[0] == KR_READY) {
					$this->InitTimers();
				}
			}
	
			public function RequestAction($Ident, $Value) {
				try {
					$this->SendDebug( __FUNCTION__ , sprintf('ReqestAction called for Ident "%s" with Value %s', $Ident, (string)$Value), 0);
		
					$VIN = strtoupper($this->ReadPropertyString('VIN'));

					if(strlen($VIN)==0) {
						throw new Exception(sprintf('Property "VIN" is empty in module "%s"', IPS_GetName($this->InstanceID)));
					}
	
					$request = null;
					$guid = self::GUID();
					switch (strtolower($Ident)) {
						case 'refresh':
							$request = $this->Refresh($VIN);
							break;
						case 'force':
							$request[] = ['Function'=>'RequestUpdate','VIN'=>$VIN, 'RequestId'=>$guid, 'ChildId'=>(string)$this->InstanceID];
							break;		
						case 'lock':
							$this->SendDebug( __FUNCTION__ , 'Setting InProgress flag to "true" for function "Lock"', 0);
							$this->UpdateInProgress('Lock', true);
							$this->SetValue($Ident, $Value);
							$request[] = ['Function'=>'Lock', 'VIN'=>$VIN, 'State'=>$Value, 'RequestId'=>$guid, 'ChildId'=>(string)$this->InstanceID];
							break;
						case 'start':
							$this->SendDebug( __FUNCTION__ , 'Setting InProgress flag to "true" for function "Start"', 0);
							$this->UpdateInProgress('Start', true);
							$this->SetValue($Ident, $Value);
							$request[] = ['Function'=>'Start', 'VIN'=>$VIN, 'State'=>$Value, 'RequestId'=>$guid, 'ChildId'=>(string)$this->InstanceID];
							break;
						case 'guard':
							$this->SetValue($Ident, $Value);
							$request[] = ['Function'=>'Guard', 'VIN'=>$VIN, 'State'=>$Value, 'RequestId'=>$guid, 'ChildId'=>(string)$this->InstanceID];
							break;
						default:
							throw new Exception(sprintf('ReqestAction called with unkown Ident "%s"', $Ident));
					}
	
					if($request!=null) {
						$this->SendDebug( __FUNCTION__ , sprintf('Sending a request with id %s to the gateway. Request is "%s"', $guid, json_encode($request)), 0);
						$this->SendDataToParent(json_encode(['DataID' => '{047CD9E9-0492-37DF-0955-3DF2F006F0A2}', 'Buffer' => $request]));
					}
	
				} catch(Exception $e) {
					$this->LogMessage(sprintf('RequestAction failed. The error was "%s"',  $e->getMessage()), KL_ERROR);
					$this->SendDebug( __FUNCTION__ , sprintf('RequestAction failed. The error was "%s"', $e->getMessage()), 0);
				}
			}
	
			public function ReceiveData($JSONString) {
				try {
					$data = json_decode($JSONString);
					$this->SendDebug( __FUNCTION__ , sprintf('Received data from parent: %s', json_encode($data->Buffer)), 0);
				 
					$msg = '';
					if(!isset($data->Buffer->Function) ) {
						$msg = 'Missing "Function"';
					} 
					if(!isset($data->Buffer->Success) ) {
						if(strlen($msg)>0) {
							$msg += ', "Buffer"';
						} else {
							$msg = 'Missing "Buffer"';
						}
					} 
					if(!isset($data->Buffer->Parameters) ) {
						if(strlen($msg)>0) {
							$msg += ', "Parameters"';
						} else {
							$msg = 'Missing "Parameters"';
						}
					} 
					if(!isset($data->Buffer->RequestId) ) {
						if(strlen($msg)>0) {
							$msg += ', "RequestId"';
						} else {
							$msg = 'Missing "RequestId"';
						}
					} 
					if(!isset($data->Buffer->Result) ) {
						if(strlen($msg)>0) {
							$msg += 'and "Result"';
						} else {
							$msg = 'Missing "Result"';
						}
					} 
	
					if(strlen($msg)>0) {
						throw new Exception('Invalid data receieved from parent. ' . $msg);
					}
					
					$success = $data->Buffer->Success;
					$result = $data->Buffer->Result;
					$requestId = $data->Buffer->RequestId;
	
					if($success) {
						$parameters = $data->Buffer->Parameters;
						$function = strtolower($data->Buffer->Function);
						switch($function) {
							case 'requestupdate':
								$this->SendDebug( __FUNCTION__ , sprintf('Handling %s()...Nothing to do', $data->Buffer->Function), 0);
								break;
							case 'status':
								$this->SendDebug( __FUNCTION__ , sprintf('Handling %s()...', $data->Buffer->Function), 0);
								if(isset($result->result->vehiclestatus)) {
									$vehicle = $result->result->vehiclestatus;

									if(!$this->InProgress('Lock')) {
										if(isset($vehicle->lockStatus->value)) {
											$value = $vehicle->lockStatus->value;
											if(is_string($value)) {
												$this->SetValueEx('Lock', strtolower($value)=='locked'?true:false);
											}
										}
									} else {
										$this->SendDebug( __FUNCTION__ , 'Lock request is in progress. Skipping Lock Update', 0);
									}

									if(isset($vehicle->odometer->value)) {
										$value = $vehicle->odometer->value;
										if(is_numeric($value)) {
											$this->SetValueEx('Odometer', (float)$value);
										}
									}

									if(!$this->InProgress('Start')) {
										if(isset($vehicle->remoteStartStatus->value)) {
											$value = $vehicle->remoteStartStatus->value;
											if(is_numeric($value)) {
												$this->SetValueEx('Start', (bool)$value);
											}
										}
									} else {
										$this->SendDebug( __FUNCTION__ , 'Start request is in progress. Skipping Start Update', 0);
									}

									if(isset($vehicle->batteryFillLevel->value)) {
										$value = $vehicle->batteryFillLevel->value;
										if(is_numeric($value)) {
											$this->SetValueEx('BatteryFillLevel', (float)$value);
										}
									}

									if(isset($vehicle->battery->batteryStatusActual->value)) {
										$value = $vehicle->battery->batteryStatusActual->value;
										if(is_numeric($value)) {
											$this->SetValueEx('12VBatterySOC', (float)$value);
										}
									}

									/*if(isset($vehicle->plugStatus->value)) {
										$value = $vehicle->plugStatus->value;
										if(is_numeric($value)) {
											if($value==1) {
												$this->SetTimerInterval('FordPassForce' . (string)$this->InstanceID, $this->ReadPropertyInteger('ForceInterval')*1000); 
											} else {
												// Throttle RequestUpdate
												$this->SetTimerInterval('FordPassForce' . (string)$this->InstanceID, $this->ReadPropertyInteger('ForceIntervalDisconnected')*60*1000); 
											}
										}
									} */

									if(isset($vehicle->alarm->value)) {
										$value = $vehicle->alarm->value;
										if(is_string($value)) {
											$this->SetValueEx('Alarm', strtolower($value)=='set'?true:false);
										}
									}

									if(isset($vehicle->tirePressure->value)) {
										$value = $vehicle->tirePressure->value;
										if(is_string($value)) {
											$this->SetValueEx('TirePressure', strtolower($value)=='status_good'?true:false);
										}
									}

									if(isset($vehicle->ignitionStatus->value)) {
										$value = $vehicle->ignitionStatus->value;
										if(is_string($value)) {
											$this->SetValueEx('IgnitionStatus', strtolower($value)=='off'?false:true);
										}
									}

									if(isset($vehicle->doorStatus)) {
										$rightRearDoor=false;
										$leftRearDoor=false;
										$driverDoor=false;
										$passengerDoor=false;
										$hoodDoor=false;
										$tailgateDoor=false;
										foreach ($vehicle->doorStatus as $doorId => $door) {
											if(isset($door->value) && is_string($door->value)) {
												switch($doorId) {
													case 'rightRearDoor':
														$rightRearDoor = strtolower($door->value)=='closed'?true:false;
														break;
													case 'leftRearDoor':
														$leftRearDoor = strtolower($door->value)=='closed'?true:false;
														break;
													case 'driverDoor':
														$driverDoor = strtolower($door->value)=='closed'?true:false;
														break;
													case 'passengerDoor':
														$passengerDoor = strtolower($door->value)=='closed'?true:false;
														break;
													case 'hoodDoor':
														$hoodDoor = strtolower($door->value)=='closed'?true:false;
														break;
													case 'tailgateDoor':
														$tailgateDoor = strtolower($door->value)=='closed'?true:false;
														break;
												}
												
											}
										}
										$value = $rightRearDoor && $leftRearDoor && $driverDoor && $passengerDoor && $hoodDoor && $tailgateDoor;
										$this->SetValueEx('DoorStatus', $value);
									}

									if(isset($vehicle->windowPosition)) {
										$driverWindowPosition=false;
										$passWindowPosition=false;
										$rearDriverWindowPos=false;
										$rearPassWindowPos=false;
										
										foreach ($vehicle->windowPosition as $windowId => $window) {
											if(isset($window->value) && is_string($window->value)) {
												switch($windowId) {
													case 'driverWindowPosition':
														$driverWindowPosition = strtolower($window->value)=='fully closed position'?true:false;
														break;
													case 'passWindowPosition':
														$passWindowPosition = strtolower($window->value)=='fully closed position'?true:false;
														break;
													case 'rearDriverWindowPos':
														$rearDriverWindowPos = strtolower($window->value)=='fully closed position'?true:false;
														break;
													case 'rearPassWindowPos':
														$rearPassWindowPos = strtolower($window->value)=='fully closed position'?true:false;
														break;
												}
											}
										}
										$value = $driverWindowPosition && $passWindowPosition && $rearDriverWindowPos && $rearPassWindowPos;
										$this->SetValueEx('WindowStatus', $value);
									}
								}
								break;
							case 'guardstatus':
								$this->SendDebug( __FUNCTION__ , sprintf('Handling %s()...', $data->Buffer->Function), 0);
								if(isset($result->result->session->gmStatus)) {
									$gmStatus = $result->result->session->gmStatus;
									if(is_string($gmStatus)) {
										$value = strtolower($gmStatus);
										$this->SetValueEx('Guard', $value=='disable'?false:true);
									}
								}
								break;
							case 'start':
								$this->SendDebug( __FUNCTION__ , sprintf('Handling %s()...', $data->Buffer->Function), 0);
								$this->SendDebug( __FUNCTION__ , 'Setting InProgress flag to "false" for function "Start"', 0);
								$this->UpdateInProgress('Start', false);
								if(is_bool($result) && isset($parameters[1]) && is_bool($parameters[1])) {
									if($result) {
										$this->SetValueEx('Start', $parameters[1]);
									} else {
										$this->SetValueEx('Start', !$parameters[1]);
									}
								}
								break;
							case 'lock':
								$this->SendDebug( __FUNCTION__ , sprintf('Handling %s()...', $data->Buffer->Function), 0);
								$this->SendDebug( __FUNCTION__ , 'Setting InProgress flag to "false" for function "Lock"', 0);
								$this->UpdateInProgress('Lock', false);
								if(is_bool($result) && isset($parameters[1]) && is_bool($parameters[1])) {
									if($result) {
										$this->SetValueEx('Lock', $parameters[1]);
									} else {
										$this->SetValueEx('Lock', !$parameters[1]);
									}
								}
								break;
							case 'guard':
								$this->SendDebug( __FUNCTION__ , sprintf('Handling %s()...', $data->Buffer->Function), 0);
								if(is_bool($result) && isset($parameters[1]) && is_bool($parameters[1])) {
									if($result) {
										$this->SetValueEx('Guard', $parameters[1]);
									} else {
										$this->SetValueEx('Guard', !$parameters[1]);
									}
								}
								break;
							case 'otainfo':
								$html = $this->CreateHTMLList($result->result);
								
								$this->SendDebug( __FUNCTION__ , sprintf('OTA info in html: %s', $html), 0);

								$this->SetValueEx('OTAInformation', $html);
								if(isset($result->result->otaAlertStatus)) {
									$this->SetValueEx('OTAAlertStatus', $result->result->otaAlertStatus);
								}

								break;
							default:
								throw new Exception(sprintf('Unknown function "%s()" receeived in repsponse with request id %s from gateway', $function, $requestId));
						}
						
						$this->SendDebug( __FUNCTION__ , sprintf('Processed the result from %s() for request id %s: %s...', $data->Buffer->Function, $requestId,json_encode($result)), 0);
					} else {
						throw new Exception(sprintf('The gateway returned an error on request id %s: %s', $requestId, $result));
					}
					
				} catch(Exception $e) {
					$this->LogMessage(sprintf('ReceiveData() failed. The error was "%s"',  $e->getMessage()), KL_ERROR);
					$this->SendDebug( __FUNCTION__ , sprintf('ReceiveData() failed. The error was "%s"',  $e->getMessage()), 0);
				}
			}

			private function CreateHTMLList($List, int $Indent = 0) : string {
				$html='';
				
				$line = $Indent>0?'<p style="text-indent:'.(string)$Indent.'px">%s</p>':'<p>%s</p>';
				$line .= PHP_EOL;
			
				foreach($List as $key => $value) {
					if(is_object($value)||is_array($value)) {
						$html.=sprintf($line, $key.':');
						if(is_object($value)) {
							$html.=$this->CreateHTMLList($value, $Indent+20);
						} elseif(sizeof($value)>0) {
							$html.=$this->CreateHTMLList($value, $Indent+20);
						}
					} else {
						if(is_bool($value)) {
							$formatedValue = $value?'true':'false';
						} elseif (is_null($value)) {
							$formatedValue = 'null';
						} else {
							$formatedValue = (string) $value;
						}
						$html.=sprintf($line,$key.': '.$formatedValue);
					}
				}
			
				return $html;
			}
	
			private function InitTimers(){
				$this->SetTimerInterval('FordPassRefresh' . (string)$this->InstanceID, $this->ReadPropertyInteger('UpdateInterval')*1000*60); 
				
				$forceInterval = $this->ReadPropertyInteger('ForceInterval')*1000*60*60;
				if($forceInterval>0) {
					$this->SetTimerInterval('FordPassForce' . (string)$this->InstanceID, $forceInterval); 
				} else {
					$this->SetTimerInterval('FordPassForce' . (string)$this->InstanceID, 0); 
				}
				
			}
	
			private function Refresh(string $VIN) : array{
				if(strlen($VIN)>0) {
					$guid=self::GUID();
					$request[] = ['Function'=>'Status','VIN'=>$VIN, 'RequestId'=>$guid, 'ChildId'=>(string)$this->InstanceID];
					$request[] = ['Function'=>'GuardStatus','VIN'=>$VIN, 'RequestId'=>$guid, 'ChildId'=>(string)$this->InstanceID];
					$request[] = ['Function'=>'OTAInfo','VIN'=>$VIN, 'RequestId'=>$guid, 'ChildId'=>(string)$this->InstanceID];
					
					return $request;
				}

				return [];
			}

			private function InProgress(string $Ident) {
				if($this->Lock('InProgress')) {
					$data = $this->GetBuffer('InProgress');
					$this->Unlock('InProgress');
					$this->SendDebug( __FUNCTION__ , sprintf('InProgress buffer is "%s"', $data), 0);
					if(strlen($data)>0) {
						$values = json_decode($data, true);
						foreach($values as $key => $value) {
							$now = microtime(true);
							if($key==$Ident) {
								$return = $now-$value['Timestamp']<60?$value['State']:false;
								if($return!=$value['State']) {
									$this->SendDebug( __FUNCTION__ , sprintf('InProgress state for "%s" has timed out. Returning "false"', $Ident), 0);
								} else {
									$this->SendDebug( __FUNCTION__ , sprintf('InProgress state for "%s" is "%s', $Ident, $return?'true':'false'), 0);
								}
								return $return;
							}
						}
						return false;
					} else {
						return false;
					}
				}
			}

			private function UpdateInProgress(string $Ident, bool $State) {
				if($this->Lock('InProgress')) {
					$data = $this->GetBuffer('InProgress');
					$this->SendDebug( __FUNCTION__ , sprintf('InProgress buffer is "%s"', $data), 0);
					if(strlen($data)>0) {
						$values = json_decode($data, true);
					} else {
						$values = [];
					}

					$values[$Ident] = array('State' => $State, 'Timestamp' => microtime(true));
					$newValues = json_encode($values);
					$this->SetBuffer('InProgress', $newValues);
					$this->Unlock('InProgress');
					$this->SendDebug( __FUNCTION__ , sprintf('InProgress buffer is updated to "%s"', $newValues), 0);
				}
			}
	
			private function SetValueEx(string $Ident, $Value) {
				$oldValue = $this->GetValue($Ident);
				if(is_bool($Value)) {
					$msgValue = $Value?'true':'false';
				} else {
					$msgValue = $Value;
				}
				//if($oldValue!=$Value) {
					$this->SetValue($Ident, $Value);
					$this->SendDebug( __FUNCTION__ , sprintf('Updated variable with Ident "%s". %s value is  "%s"', $Ident, $oldValue==$Value?'Refreshed':'New', (string)$msgValue), 0);
				//}
			}
		}