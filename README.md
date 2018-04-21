Command-line Network Testing Tool
=================================

This is a very simple tool designed to quickly set up a debuggable TCP/IP echo server or client.  The server waits for a connection and the client will keep retrying until it successfully connects.  Designed for diagnosing network connectivity issues.

Features
--------

* Simple TCP/IP echo server/client with optional SSL/TLS support.
* A complete, question/answer enabled command-line interface.  Nothing to compile.
* GenericServer class.  Very noisy when debug mode is turned on (a good thing in this case).
* Also has a liberal open source license.  MIT or LGPL, your choice.
* Designed for relatively painless integration into your environment.
* Sits on GitHub for all of that pull request and issue tracker goodness to easily submit changes and ideas respectively.

Getting Started
---------------

The easiest way to get started is to play with the command-line interface.  The command-line interface is question/answer enabled, which means all you have to do is run:

````
php net-test.php
````

Once you grow tired of manually entering information, you can pass in some or all of the answers to the questions on the command-line:

````
php net-test.php server bind= port=12345 ssl=N

php net-test.php -s client bind= host=127.0.0.1 port=12345 ssl=N retry= msg="It works!"
````

The -s option suppresses entry output.
