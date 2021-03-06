<?php
declare(strict_types=1);

include __DIR__ . "/../libs/fordpass.php";
include __DIR__ . "/../libs/traits.php";

	class FordpassGateway extends IPSModule {
		use Lock;

		public function Create()
		{
			//Never delete this line!
			parent::Create();

		$this->RegisterPropertyString('Username', '');
		$this->RegisterPropertyString('Password', '');
		$this->RegisterPropertyString('Region', 'UK and Europe');

		$this->RegisterPropertyBoolean('SkipSSLCheck', true);

		$this->RegisterTimer('FordPassRefreshToken' . (string)$this->InstanceID, 0, 'IPS_RequestAction(' . (string)$this->InstanceID . ', "RefreshToken", 0);'); 

		$this->RegisterMessage(0, IPS_KERNELMESSAGE);
		}

		public function Destroy() {
			//Never delete this line!
			parent::Destroy();
		}

		public function ApplyChanges() {
			//Never delete this line!
			parent::ApplyChanges();

			if (IPS_GetKernelRunlevel() == KR_READY) {
				$this->InitFordPass();
			}
		}

		public function MessageSink($TimeStamp, $SenderID, $Message, $Data) {
			parent::MessageSink($TimeStamp, $SenderID, $Message, $Data);
	
			if ($Message == IPS_KERNELMESSAGE && $Data[0] == KR_READY) {
				$this->InitFordPass();
			}
		}

		public function RequestAction($Ident, $Value) {
			try {
				$this->SendDebug( __FUNCTION__ , sprintf('ReqestAction called for Ident "%s" with Value %s', $Ident, $Value), 0);
	
				switch (strtolower($Ident)) {
					case 'async':
						$this->HandleAsyncRequest($Value);
						break;
					case 'refreshtoken':
						$this->RefreshToken();
						break;
					default:
						throw new Exception(sprintf('ReqestAction called with unkown Ident "%s"', $Ident));
				}
			} catch(Exception $e) {
				$this->LogMessage(sprintf('RequestAction failed. The error was "%s"',  $e->getMessage()), KL_ERROR);
				$this->SendDebug( __FUNCTION__ , sprintf('RequestAction failed. The error was "%s"', $e->getMessage()), 0);
			}
		}

		public function ForwardData($JSONString) {
			$this->SendDebug( __FUNCTION__ , sprintf('Received a request from a child. The request was "%s"', $JSONString), 0);
	
			$data = json_decode($JSONString);
			$requests = json_encode($data->Buffer);
			$script = "IPS_RequestAction(" . (string)$this->InstanceID . ", 'Async', '" . $requests . "');";
	
			$this->SendDebug( __FUNCTION__ , 'Executing the request(s) in a new thread...', 0);
					
			// Call RequestAction in another thread
			IPS_RunScriptText($script);
	
			return true;
		
		}

		private function HandleAsyncRequest(string $Requests) {
			$requests = json_decode($Requests);
	
			foreach($requests as $request) {
			
				if(!isset($request->Function)||!isset($request->ChildId)) {
					throw new Exception(sprintf('HandleAsyncRequest: Invalid formated request. Key "Function" and/or "ChildId" is missing. The request was "%s"', $request));
				}
				
				if(!isset($request->VIN)) {
					throw new Exception(sprintf('HandleAsyncRequest: Invalid formated request. Key "VIN" is missing. The request was "%s"', $request));
				}

				if(!isset($request->RequestId)) {
					throw new Exception(sprintf('HandleAsyncRequest: Invalid formated request. Key "RequestId" is missing. The request was "%s"', $request));
				}

				$function = strtolower($request->Function);
				$childId =  $request->ChildId;
				$VIN = $request->VIN;
				$requestId = $request->RequestId;
				
				switch($function) {
					case 'requestupdate':
						$this->ExecuteFordPassRequest($childId, $requestId, 'RequestUpdate', array($VIN));
						break;
					case 'status':
						$this->ExecuteFordPassRequest($childId, $requestId, 'Status', array($VIN));
						break;
					case 'otainfo':
							$this->ExecuteFordPassRequest($childId, $requestId, 'OTAInfo', array($VIN));
							break;
					case 'guardstatus':
						$this->ExecuteFordPassRequest($childId, $requestId, 'GuardStatus', array($VIN));
						break;
					case 'start':
						if(!isset($request->State)) {
							throw new Exception(sprintf('HandleAsyncRequest: Invalid formated request. Key "State" is missing. The request was "%s"', $request));
						}

						$this->ExecuteFordPassRequest($childId, $requestId, 'Start', array($VIN, $request->State));
						break;
					case 'lock':
						if(!isset($request->State)) {
							throw new Exception(sprintf('HandleAsyncRequest: Invalid formated request. Key "State" is missing. The request was "%s"', $request));
						}

						$this->ExecuteFordPassRequest($childId, $requestId, 'Lock', array($VIN, $request->State));
						break;
					case 'guard':
						if(!isset($request->State)) {
							throw new Exception(sprintf('HandleAsyncRequest: Invalid formated request. Key "State" is missing. The request was "%s"', $request));
						}

						$this->ExecuteFordPassRequest($childId,  $requestId, 'Guard', array($VIN, $request->State));
						break;
					default:
						throw new Exception(sprintf('HandleAsyncRequest failed. Unknown function "%s"', $function));
				}
			}
		}

		private function ExecuteFordPassRequest(string $ChildId, string $RequestId, string $Function, array $Args=null) {
		
			$this->SendDebug( __FUNCTION__ , sprintf('Executing FordPass::%s() for component %s. Request id is %s...', $Function, isset($Args[0])?$Args[0]:'N/A', $RequestId), 0);
	
			$fordpass = null;
					
			$token = $this->GetTokenFromBuffer();
			if($token==null) {
				$fordpass = $this->InitFordPass();
			} else {
				$username = $this->ReadPropertyString('Username');
				$password = $this->ReadPropertyString('Password');
				$region = $this->ReadPropertyString('Region');
	
				$fordpass = new FordPass($region, $username, $password, $token->AccessToken, $token->RefreshToken, $token->Expires);
			}
	
			$return['Function'] = $Function;
			$return['Parameters'] = $Args;
			$return['RequestId'] = $RequestId;
	
			try{
				if($fordpass==null) {
					throw new Exception('Unable to initialize the FordPass class');
				}
	
				if($this->ReadPropertyBoolean('SkipSSLCheck')) {
					$fordpass->DisableSSLCheck();
				}
	
				//$this->SendDebug( __FUNCTION__ , sprintf('Executing function "%s" ...', $Function), 0);

				if($Args == null) {
					$result = call_user_func(array($fordpass, $Function));
				} else {
					$result = call_user_func_array(array($fordpass, $Function), $Args);
				}
				
				$this->SendDebug( __FUNCTION__ , sprintf('FordPass API returned "%s" for %s()', json_encode($result), $Function), 0);
				
			} catch(Exception $e) {
				$this->SendDebug( __FUNCTION__ , sprintf('ExecuteFordPassRequest() failed for function %s() in request id %s. The error was "%s:%d"', $Function, $RequestId, $e->getMessage(), $e->getCode()), 0);
				$this->LogMessage(sprintf('ExecuteFordPassRequest() failed for function %s() in request id %s. The error was "%s"', $Function, $RequestId,$e->getMessage()), KL_ERROR);
				
				$return['Success'] = false;
				$return['Result'] = $e->getMessage();
			}
	
			if(!isset($return['Success'])) {
				$return['Success'] = true;
				$return['Result'] = $result;
			}
			
			$this->SendDebug( __FUNCTION__ , sprintf('Sending the result back to the child with Id %s. Result sendt is "%s"', (string)$ChildId, json_encode($return)), 0);
			$this->SendDataToChildren(json_encode(["DataID" => "{677E0420-B69C-597E-C909-39877953E1DC}", "ChildId" => $ChildId, "Buffer" => $return]));
		}
	

		private function InitFordPass() {
			$this->SendDebug( __FUNCTION__ , 'Initializing the FordPass Class...', 0);
	
			$this->SetTimerInterval('FordPassRefreshToken' . (string)$this->InstanceID, 0); // Disable the timer
	
			$username = $this->ReadPropertyString('Username');
			$password = $this->ReadPropertyString('Password');
			$region = $this->ReadPropertyString('Region');
	
			if(strlen($username)==0) {
				$this->LogMessage(sprintf('InitFordPass(): Missing property "Username" in module "%s"',  __FUNCTION__ ), KL_ERROR);
				$this->SendDebug( __FUNCTION__ , sprintf('InitFordPass(): Missing property "Username" in module "%s"', IPS_GetName($this->InstanceID)), 0);
				
				return null;
			}
	
			$fordpass = new FordPass($region, $username, $password);
			
			if($this->ReadPropertyBoolean('SkipSSLCheck')) {
				$fordpass->DisableSSLCheck();
			}
			
			try {
				$this->SendDebug( __FUNCTION__ , 'Connecting to FordPass API...', 0);
				$fordpass->Connect();
				$token = $fordpass->GetToken();
				
				$this->SendDebug( __FUNCTION__ , sprintf('Saving Token for later use: %s', json_encode($token)), 0);
				$this->AddTokenToBuffer($token);
				
				$expiresIn = ($token->ExpiresIn-5*60); // Set to 5 minutes before token timeout
	
				$this->SetTimerInterval('FordPassRefreshToken' . (string)$this->InstanceID, $expiresIn*1000); 
				$this->SendDebug( __FUNCTION__ , sprintf('Token Refresh Timer set to %s second(s)', (string)$expiresIn), 0);

				return $fordpass;
			} catch(Exception $e) {
				$this->LogMessage(sprintf('Failed to connect to FordPass API. The error was "%s"',  $e->getMessage()), KL_ERROR);
				$this->SendDebug( __FUNCTION__ , sprintf('Failed to connec to FordPass API. The error was "%s"', $e->getMessage()), 0);
				return null;
			}
		}

		private function RefreshToken() {
			$this->SendDebug( __FUNCTION__ , 'Refreshing the FordPass Class...', 0);
	
			$this->SetTimerInterval('FordPassRefreshToken' . (string)$this->InstanceID, 0); // Disable the timer
	
			$fordpass = null;
			
			$token = $this->GetTokenFromBuffer();
			if($token==null) {
				$fordpass = $this->InitFordPass();
			} else {
				$username = $this->ReadPropertyString('Username');
				$password = $this->ReadPropertyString('Password');	
				$region = $this->ReadPropertyString('Region');
	
				$fordpass = new FordPass($region, $username, $password, $token->AccessToken, $token->RefreshToken, $token->Expires);
			}
			
			try {
				if($fordpass==null) {
					throw new Exception('Unable to refresh the FordPass class');
				}
	
				if($this->ReadPropertyBoolean('SkipSSLCheck')) {
					$fordpass->DisableSSLCheck();
				}
	
				$fordpass->RefreshToken();
	
				$token = $fordpass->GetToken();
	
				$this->SendDebug( __FUNCTION__ , sprintf('Saving refreshed Token for later use: %s', json_encode($token)), 0);
				$this->AddTokenToBuffer($token);
	
				$expiresIn = ($token->ExpiresIn-5*60); // Set to 5 minutes before token timeout
	
				$this->SetTimerInterval('FordPassRefreshToken' . (string)$this->InstanceID, $expiresIn*1000); 
				$this->SendDebug( __FUNCTION__ , sprintf('Token Refresh Timer set to %s second(s)', (string)$expiresIn), 0);
			} catch(Exception $e) {
				$this->AddTokenToBuffer(null);	
				throw new Exception(sprintf('RefreshToken() failed. The error was "%s"', $e->getMessage()));
			}
		}

		private function GetTokenFromBuffer() {
			if($this->Lock('Token')) {
				$jsonToken = $this->GetBuffer('Token');
				
				if(strlen($jsonToken)==0) {
					$this->SendDebug( __FUNCTION__ , sprintf('Missing token in the buffer', $jsonToken), 0);
					$this->Unlock('Token');
					return null;
				}
	
				$this->SendDebug( __FUNCTION__ , sprintf('Got token "%s" from the buffer', $jsonToken), 0);
				$this->Unlock('Token');
				
				$token = json_decode($jsonToken);
				$expires = new DateTime($token->Expires->date, new DateTimeZone($token->Expires->timezone));
				$token->Expires = $expires; 
				
				return $token;
			} 
	
			return null;
		}
	
		private function AddTokenToBuffer($Token) {
			if($this->Lock('Token')) {
				if($Token==null)
					$token = '';
				else
					$token = json_encode($Token);
				$this->SetBuffer('Token', $token);
				$this->SendDebug( __FUNCTION__ , sprintf('Added token "%s" to the buffer', $token), 0);
				$this->Unlock('Token');
			}
		}
	
	
	}