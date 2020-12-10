<?php
	// Network server/client testing command line tools.
	// (C) 2018 CubicleSoft.  All Rights Reserved.

	if (!isset($_SERVER["argc"]) || !$_SERVER["argc"])
	{
		echo "This file is intended to be run from the command-line.";

		exit();
	}

	// Temporary root.
	$rootpath = str_replace("\\", "/", dirname(__FILE__));

	require_once $rootpath . "/support/cli.php";
	require_once $rootpath . "/support/generic_server.php";

	// Process the command-line options.
	$options = array(
		"shortmap" => array(
			"s" => "suppressoutput",
			"?" => "help"
		),
		"rules" => array(
			"suppressoutput" => array("arg" => false),
			"help" => array("arg" => false)
		)
	);
	$args = CLI::ParseCommandLine($options);

	if (isset($args["opts"]["help"]))
	{
		echo "Network server/client command-line tools\n";
		echo "Purpose:  Start a test echo server or client directly from the command-line.\n";
		echo "\n";
		echo "This tool is question/answer enabled.  Just running it will provide a guided interface.  It can also be run entirely from the command-line if you know all the answers.\n";
		echo "\n";
		echo "Syntax:  " . $args["file"] . " [options] [cmd [cmdoptions]]\n";
		echo "Options:\n";
		echo "\t-s   Suppress entry output.\n";
		echo "\n";
		echo "Examples:\n";
		echo "\tphp " . $args["file"] . "\n";
		echo "\tphp " . $args["file"] . " server bind= port=12345 ssl=N\n";
		echo "\tphp " . $args["file"] . " client bind= host=127.0.0.1 port=12345 ssl=N retry= msg=\"It works!\"\n";

		exit();
	}

	$suppressoutput = (isset($args["opts"]["suppressoutput"]) && $args["opts"]["suppressoutput"]);

	// Get the command group.
	$cmds = array(
		"server" => "Start an echo server",
		"client" => "Connect to an echo server",
		"web-tcp" => "Repeatedly retrieve a single URL"
	);

	$cmd = CLI::GetLimitedUserInputWithArgs($args, false, "Command", false, "Available commands:", $cmds, true, $suppressoutput);

	function DisplayResult($result)
	{
		echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";

		exit();
	}

	if ($cmd === "server")
	{
		CLI::ReinitArgs($args, array("bind", "port", "ssl", "cert", "key"));

		$bind = CLI::GetUserInputWithArgs($args, "bind", "Bind to IP", "0.0.0.0", "The bind to IP address is usually 0.0.0.0 or 127.0.0.1 for IPv4 and [::0] or [::1] for IPv6.", $suppressoutput);
		$port = (int)CLI::GetUserInputWithArgs($args, "port", "Port", false, "", $suppressoutput);
		$ssl = CLI::GetYesNoUserInputWithArgs($args, "ssl", "SSL", "N", "", $suppressoutput);

		if ($ssl)
		{
			$certfile = CLI::GetUserInputWithArgs($args, "cert", "SSL certificate chain", false, "A SSL certificate chain file consists of the server certificate and CA intermediates.", $suppressoutput);
			$keyfile = CLI::GetUserInputWithArgs($args, "key", "SSL private key", false, "The private key file contains the private key for the server certificate.", $suppressoutput);

			$sslopts = array(
				"local_cert" => $certfile,
				"local_pk" => $keyfile
			);
		}

		echo "Starting server...\n";
		$es = new GenericServer();
		$es->SetDebug(true);
		$result = $es->Start($bind, $port, ($ssl ? $sslopts : false));
		if (!$result["success"])  DisplayResult($result);

		echo "Ready.\n";

		$tracker = array();

		do
		{
			$result = $es->Wait();
			if (!$result["success"])  break;

			// Handle active clients.
			foreach ($result["clients"] as $id => $client)
			{
				if (!isset($tracker[$id]))
				{
					echo "Client " . $id . " connected.\n";

					$tracker[$id] = array();
				}

				if ($client->readdata != "")
				{
					echo "Client " . $id . " received:  " . $client->readdata . "\n";

					$client->writedata .= $client->readdata;
					$client->readdata = "";
				}
			}

			// Do something with removed clients.
			foreach ($result["removed"] as $id => $result2)
			{
				if (isset($tracker[$id]))
				{
					echo "Client " . $id . " disconnected.\n";

					echo "Client " . $id . " disconnected.  " . $result2["client"]->recvsize . " bytes received, " . $result2["client"]->sendsize . " bytes sent.  Disconnect reason:\n";
					echo json_encode($result2["result"], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
					echo "\n";

					unset($tracker[$id]);
				}
			}
		} while(1);
	}
	else if ($cmd === "client")
	{
		CLI::ReinitArgs($args, array("bind", "host", "port", "ssl", "retry", "msg"));

		$bind = CLI::GetUserInputWithArgs($args, "bind", "Bind to IP", "", "Binding to a specific IP controls the interface that packets will be sent out on.  Leave blank for the default interface.", $suppressoutput);
		$host = CLI::GetUserInputWithArgs($args, "host", "Host", false, "", $suppressoutput);
		$port = (int)CLI::GetUserInputWithArgs($args, "port", "Port", false, "", $suppressoutput);
		$ssl = CLI::GetYesNoUserInputWithArgs($args, "ssl", "SSL", "N", "", $suppressoutput);
		$retry = (int)CLI::GetUserInputWithArgs($args, "retry", "Retry", "-1", "", $suppressoutput);
		$msg = CLI::GetUserInputWithArgs($args, "msg", "Message", false, "", $suppressoutput);

		function GetSafeSSLOpts($cafile = true, $cipherstype = "intermediate")
		{
			// Result array last updated May 3, 2017.
			$result = array(
				"ciphers" => GenericServer::GetSSLCiphers($cipherstype),
				"disable_compression" => true,
				"allow_self_signed" => false,
				"verify_peer" => true,
				"verify_depth" => 5
			);

			if ($cafile === true)  $result["auto_cainfo"] = true;
			else if ($cafile !== false)  $result["cafile"] = $cafile;

			if (isset($result["auto_cainfo"]))
			{
				unset($result["auto_cainfo"]);

				$cainfo = ini_get("curl.cainfo");
				if ($cainfo !== false && strlen($cainfo) > 0)  $result["cafile"] = $cainfo;
				else if (file_exists(str_replace("\\", "/", dirname(__FILE__)) . "/support/cacert.pem"))  $result["cafile"] = str_replace("\\", "/", dirname(__FILE__)) . "/support/cacert.pem";
			}

			return $result;
		}

		$context = stream_context_create();
		if ($bind !== "")  $context["socket"] = array("bindto" => $bind . ":0");
		if ($ssl)
		{
			$protocol = "ssl";

			$sslopts = GetSafeSSLOpts();
			foreach ($sslopts as $key => $val)  stream_context_set_option($context, "ssl", $key, $val);
		}
		else
		{
			$protocol = "tcp";
		}

		// Connect to the host.
		echo "\n";
		echo "Connecting to " . $protocol . "://" . $host . ":" . $port . "...\n";
		do
		{
			$fp = stream_socket_client($protocol . "://" . $host . ":" . $port, $errornum, $errorstr, 3, STREAM_CLIENT_CONNECT, $context);
			if ($fp !== false)  break;

			if ($retry < 0)  continue;
			else if ($retry > 1)
			{
				$retry--;
				echo "Connection attempt failed.  Retries left:  " . $retry . "\n";

				continue;
			}
			else
			{
				echo "Connection attempt failed.\n";

				exit();
			}
		} while (1);

		// Send the message.
		for ($x = 0; $x < 3; $x++)
		{
			echo "Sending '" . $msg . "'...\n";
			fwrite($fp, $msg);

			$msg2 = fread($fp, strlen($msg));
			echo "Received '" . $msg2 . "'.\n";

			usleep(250000);
		}

		// Close the connection.
		echo "Closing connection.\n";
		fclose($fp);
	}
	else if ($cmd === "web-tcp")
	{
		CLI::ReinitArgs($args, array("bind", "url", "frequency", "rawfile"));

		$bind = CLI::GetUserInputWithArgs($args, "bind", "Bind to IP", "", "Binding to a specific IP controls the interface that packets will be sent out on.  Leave blank for the default interface.", $suppressoutput);
		$url = CLI::GetUserInputWithArgs($args, "url", "HTTP(S) URL", false, "Ideally should be a URL to a web server you control and the URL only returns a few bytes of data.  GET request only.", $suppressoutput);
		$freq = (int)CLI::GetUserInputWithArgs($args, "frequency", "Request frequency (seconds)", "1", "", $suppressoutput);
		if ($freq < 1)  $freq = 1;
		$rawfile = CLI::GetUserInputWithArgs($args, "rawfile", "Raw CSV file", "", "Where to store output for later analysis.  Useful for tracing difficult connectivity issues.", $suppressoutput);

		if ($rawfile === "")  $fp = false;
		else
		{
			$init = (!file_exists($rawfile));
			$fp = fopen($rawfile, ($init ? "wb" : "ab"));
			if ($init)  fputcsv($fp, array("timestamp", "url", "active_reqs", "result", "bytes_sent", "bytes_recv", "total_time", "req_time", "response_time"));
		}

		require_once $rootpath . "/support/web_browser.php";
		require_once $rootpath . "/support/multi_async_helper.php";

		$nextts = microtime(true);

		// Use a multi-async helper to maintain request frequency consistency.
		$pages = array();
		$helper = new MultiAsyncHelper();

		while (1)
		{
			$ts = microtime(true);
			if ($ts >= $nextts)
			{
				$options = array();
				if ($bind !== "")  $options["source_ip"] = $bind;

				$pages[(int)$ts] = array("ts" => $ts, "web" => new WebBrowser());
				$pages[(int)$ts]["web"]->ProcessAsync($helper, (int)$ts, NULL, $url, $options);

				$nextts += $freq;
			}

			$result = $helper->Wait(0);
			if (!$result["success"])
			{
				var_dump($result);

				exit();
			}

			// Process finished pages.
			foreach ($result["removed"] as $ts2 => $info)
			{
				echo "[" . date("Y-m-d H:i:s", $ts2) . "] ";

				if (!$info["result"]["success"])
				{
					echo $info["result"]["error"] . " (" . $info["result"]["errorcode"] . ")\n";

					if ($fp !== false)
					{
						fputcsv($fp, array(date("Y-m-d H:i:s", $ts2), $url, count($pages), $info["result"]["error"] . " (" . $info["result"]["errorcode"] . ")", "0", "0", $ts - $pages[$ts2]["ts"], "0", "0"));
						fflush($fp);
					}
				}
				else
				{
					if ($info["result"]["response"]["code"] != 200)  echo $info["result"]["response"]["line"] . "\n";
					else  echo "OK\n";

					if ($fp !== false)
					{
						fputcsv($fp, array(date("Y-m-d H:i:s", $ts2), $url, count($pages), $info["result"]["response"]["line"], $info["result"]["rawsendsize"], $info["result"]["rawrecvsize"], $info["result"]["endts"] - $info["result"]["startts"], $info["result"]["recvstart"] - $info["result"]["sendstart"], $info["result"]["endts"] - $info["result"]["recvstart"]));
						fflush($fp);
					}
				}

				unset($pages[$ts2]);
			}

			usleep(250000);
		}
	}
?>