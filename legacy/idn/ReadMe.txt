*******************************************************************************
*                                                                             *
*                    IDNA Convert (IdnaConvert.class.php)                    *
*                                                                             *
* http://idnaconv.phlymail.de                     mailto:phlymail@phlylabs.de *
*******************************************************************************
* (c) 2004-2008 phlyLabs, Berlin                                              *
* This file is encoded in UTF-8                                               *
*******************************************************************************

Introduction
------------

The class IdnaConvert allows to convert internationalized domain names
(see RFC 3490, 3491, 3492 and 3454 for detials) as they can be used with various
registries worldwide to be translated between their original (localized) form
and their encoded form as it will be used in the DNS (Domain Name System).

The class provides two public methods, encode() and decode(), which do exactly
what you would expect them to do. You are allowed to use complete domain names,
simple strings and complete email addresses as well. That means, that you might
use any of the following notations:

- www.nörgler.com
- xn--nrgler-wxa
- xn--brse-5qa.xn--knrz-1ra.info

Errors, incorrectly encoded or invalid strings will lead to either a FALSE
response (when in strict mode) or to only partially converted strings.
You can query the occured error by calling the method get_last_error().

Unicode strings are expected to be either UTF-8 strings, UCS-4 strings or UCS-4
arrays. The default format is UTF-8. For setting different encodings, you can
call the method setParams() - please see the inline documentation for details.
ACE strings (the Punycode form) are always 7bit ASCII strings.

ATTENTION: As of version 0.6.0 of this class it is written in the OOP style of PHP5.
Since PHP4 is no longer actively maintained, you should switch to PHP5 as fast as 
possible.
We expect to see no compatibility issues with the upcoming PHP6, too.
