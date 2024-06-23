# DataResolutionReminder - Redcap External Module

## What does it do?

## Installing

You can install the module from the REDCap EM repo or drop it directly in your modules folder (i.e. `redcap/modules/better_search_v1.0.0`) manually.

## Configuration

## TODO

* Idea - Don't instant search, wait for a click/enter
* Idea - parse and take a best guess at what the data might be (phone, dob, etc) and search those items
* Idea - In config allow specifiying what fields to prioritze search on
* Idea - Search identifier fields? The user is probably searching on an identifer
* Idea - Every search should kick off two quieires, one fast (above) and one slow that searches everyhting
* Search doesn't work yet
* No clue what the new search should look like
* Currently on hold as I don't think I'll be able to impliemnt a meaningfully better fast-search method. General idea is to have a list of cached fields, we grab all of them and pass them down to the client, but this could be slow on larger projects. If we fail we would need to fall back on the normal redcap method. Maybe we should just focus on making the current redcap method better?
