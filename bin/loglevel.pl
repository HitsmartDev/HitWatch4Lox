#!/usr/bin/perl
# HitWatch4Lox – LoxBerry Log-Level Bridge
# Gibt den aktuell konfigurierten Log-Level zurück (0-7).
# Aufgerufen vom Python-Daemon beim Start.
# REPLACELBPPLUGINDIR wird vom LoxBerry-Installer durch den Plugin-Namen ersetzt.

use strict;
use warnings;
use LoxBerry::System;

# Log-Level ermitteln (wird in der LoxBerry-Oberfläche eingestellt)
my $loglevel = eval { LoxBerry::System::pluginloglevel("REPLACELBPPLUGINDIR") } // eval { LoxBerry::System::loglevel() } // '';
$loglevel = 6 unless defined $loglevel && $loglevel =~ /^\d+$/;

print "$loglevel\n";
exit 0;
