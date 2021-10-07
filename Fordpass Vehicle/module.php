<?php

	declare(strict_types=1);
	
	//include __DIR__ . "/../libs/traits.php";
	
		class FordpassVehicle extends IPSModule {
			//use Profiles;
	
			public function Create(){
				//Never delete this line!
				parent::Create();
	
				$this->ConnectParent('{4651EC8E-D0BB-1354-1167-BB7C87729F19}');
	
				$this->RegisterPropertyInteger('UpdateInterval', 15);
				$this->RegisterPropertyString('VIN', '');
					
				$this->RegisterVariableBoolean('Start', 'Start', '~Switch', false);
				$this->EnableAction('Start');

				$this->RegisterVariableBoolean('Lock', 'Lock', '~Lock', false);
				$this->EnableAction('Lock');

				$this->RegisterVariableBoolean('Guard', 'SecuriAlert', '~Switch', false);
				$this->EnableAction('Guard');
					
				$this->RegisterTimer('FordPassRefresh' . (string)$this->InstanceID, 0, 'IPS_RequestAction(' . (string)$this->InstanceID . ', "Refresh", 0);'); 
	
				$this->RegisterMessage(0, IPS_KERNELMESSAGE);
			}
	
			public function Destroy(){
				$module = json_decode(file_get_contents(__DIR__ . '/module.json'));
				if(count(IPS_GetInstanceListByModuleID($module->id))==0) {
					
				}
	
				//Never delete this line!
				parent::Destroy();
			}
	
			public function ApplyChanges(){
				//Never delete this line!
				parent::ApplyChanges();
	
				$this->SetReceiveDataFilter('.*"ChildId":"' . (string)$this->InstanceID .'".*');
	
				if (IPS_GetKernelRunlevel() == KR_READY) {
					$this->InitTimer();
				}
			}
	
			public function MessageSink($TimeStamp, $SenderID, $Message, $Data) {
				parent::MessageSink($TimeStamp, $SenderID, $Message, $Data);
	
				if ($Message == IPS_KERNELMESSAGE && $Data[0] == KR_READY) {
					$this->InitTimer();
				}
			}
	
			public function RequestAction($Ident, $Value) {
				try {
					$this->SendDebug(IPS_GetName($this->InstanceID), sprintf('ReqestAction called for Ident "%s" with Value %s', $Ident, (string)$Value), 0);
		
					$VIN = $this->ReadPropertyString('VIN');

					if(strlen($VIN)==0) {
						throw new Exception(sprintf('Property "VIN" is empty in module "%s"', IPS_GetName($this->InstanceID)));
					}
	
					$request = null;
					switch (strtolower($Ident)) {
						case 'refresh':
							$request = $this->Refresh($VIN);
							$this->InitTimer(); // Reset timer back to configured interval
							break;
						case 'lock':
							$this->SetValue($Ident, $Value);
							$request[] = ['ChildId'=>(string)$this->InstanceID,'Function'=>'Lock','VIN'=>$VIN, 'State' => $Value];
							break;
						case 'start':
							$this->SetValue($Ident, $Value);
							$request[] = ['ChildId'=>(string)$this->InstanceID,'Function'=>'Start','VIN'=>$VIN, 'State' => $Value];
							break;
						case 'guard':
							$this->SetValue($Ident, $Value);
							$request[] = ['ChildId'=>(string)$this->InstanceID,'Function'=>'Guard','VIN'=>$VIN, 'State' => $Value];
							break;
						default:
							throw new Exception(sprintf('ReqestAction called with unkown Ident "%s"', $Ident));
					}
	
					if($request!=null) {
						$this->SendDebug(IPS_GetName($this->InstanceID), sprintf('Sending a request to the gateway: %s', json_encode($request)), 0);
						$this->SendDataToParent(json_encode(['DataID' => '{90162874-BC86-2CD4-D8F6-AFCB86B7D3CC}', 'Buffer' => $request]));
					}
	
				} catch(Exception $e) {
					$this->LogMessage(sprintf('RequestAction failed. The error was "%s"',  $e->getMessage()), KL_ERROR);
					$this->SendDebug(IPS_GetName($this->InstanceID), sprintf('RequestAction failed. The error was "%s"', $e->getMessage()), 0);
				}
			}
	
			public function ReceiveData($JSONString) {
				try {
					$data = json_decode($JSONString);
					$this->SendDebug(IPS_GetName($this->InstanceID), sprintf('Received data from parent: %s', json_encode($data->Buffer)), 0);
				 
					$msg = '';
					if(!isset($data->Buffer->Function) ) {
						$msg = 'Missing "Function"';
					} 
					if(!isset($data->Buffer->Success) ) {
						if(strlen($msg)>0) {
							$msg += ', missing "Buffer"';
						} else {
							$msg = 'Missing "Buffer"';
						}
					} 
					if(!isset($data->Buffer->Result) ) {
						if(strlen($msg)>0) {
							$msg += ', missing "Result"';
						} else {
							$msg = 'Missing "Result"';
						}
					} 
	
					if(strlen($msg)>0) {
						throw new Exception('Invalid data receieved from parent. ' . $msg);
					}
					
					$success = $data->Buffer->Success;
					$result = $data->Buffer->Result;
	
					if($success) {
						$function = strtolower($data->Buffer->Function);
						switch($function) {
							case 'status':
									
								break;
							case 'start':
								
								break;
							case 'lock':
								
								break;
							case 'guard':
								
								
								break;
							default:
								throw new Exception(sprintf('Unknown function "%s()" receeived in repsponse from gateway', $function));
						}
						
						$this->SendDebug(IPS_GetName($this->InstanceID), sprintf('Processed the result from %s(): %s...', $data->Buffer->Function, json_encode($result)), 0);
					} else {
						throw new Exception(sprintf('The gateway returned an error: %s',$result));
					}
					
				} catch(Exception $e) {
					$this->LogMessage(sprintf('ReceiveData() failed. The error was "%s"',  $e->getMessage()), KL_ERROR);
					$this->SendDebug(IPS_GetName($this->InstanceID), sprintf('ReceiveData() failed. The error was "%s"',  $e->getMessage()), 0);
				}
			}
	
			private function InitTimer(){
				$this->SetTimerInterval('FordPassRefresh' . (string)$this->InstanceID, $this->ReadPropertyInteger('UpdateInterval')*1000); 
			}
	
			private function Refresh(string $VIN) : array{
				if(strlen($ChargerId)>0) {
					$request[] = ['ChildId'=>(string)$this->InstanceID,'Function'=>'Status','VIN'=>$VIN];
					
					return $request;
				}
			}
	
			private function SetValueEx(string $Ident, $Value) {
				$oldValue = $this->GetValue($Ident);
				if($oldValue!=$Value) {
					$this->SetValue($Ident, $Value);
					$this->SendDebug(IPS_GetName($this->InstanceID), sprintf('Modifed variable with Ident "%s". New value is  "%s"', $Ident, (string)$Value), 0);
				}
			}
		}