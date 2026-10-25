# Better Search - REDCap External Module

## What does it do?

Better Search replaces REDCap's standard Add / Edit Records page with a faster, cleaner search interface designed for projects with lots of records. Instead of loading huge record dropdowns or scanning the entire data table on every keystroke, it checks your most important fields first (like Record ID, Secondary Unique Field, Custom Record Label fields, identifiers, and any extra fields you pick in the module settings) and then searches the rest of the project in the background if you want a full deep search.

It also handles the everyday search quirks that usually trip people up in REDCap. You can type dates as `MM/DD/YYYY` even though REDCap stores them as `YYYY-MM-DD`, search phone numbers without worrying about parentheses or dashes, match multiple-choice text labels (including `sql` fields), or type a full name like `John Smith` when first and last name live in separate fields. Once results are on screen, you can keep typing and pressing Enter to stack filter chips and narrow down the list right in the browser.

Multi-arm projects and Data Access Groups work out of the box too. You can switch arms without reloading the page, filter search results by DAG, and even pick which DAG a new record should belong to right when you click Add new record.

## Installing

You can install the module from the REDCap External Module Repository or drop it directly into your modules folder (e.g., `redcap/modules/better_search_v1.0.0`) and enable it in the Control Center and your project.
