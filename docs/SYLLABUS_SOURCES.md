# Syllabus data: sources and limits

The app does **not** invent syllabus topics. The file loaded by **Syllabus -> Load NIE syllabus** is `database/data/nie_syllabus_2017.csv`. It was extracted on 28 October 2026 from the National Institute of Education (NIE) syllabus documents for Grades 12 and 13. Every row carries its source link and, where available, the official competency-level number.

| Subject | Structure loaded | Source document |
|---|---|---|
| Physics | 11 units, 61 competency levels (Measurement; Mechanics; Oscillations and Waves; Thermal Physics; Gravitational Field; Electrostatic Field; Magnetic Field; Current Electricity; Electronics; Mechanical Properties of Matter; Matter and Radiation) | https://nie.lk/pdffiles/tg/eALSyl%20Physics.pdf |
| Combined Mathematics | 2 units (Combined Mathematics I and II) with 46 topics (28 in I including "Review of basic algebra", 18 in II) | https://nie.lk/pdffiles/tg/eALSyl%20Com%20Maths.pdf (effective from 2017) |
| IT (ICT) | 14 units, 82 competency levels (Concept of ICT through Project) | NIE ICT syllabus, Grades 12 and 13 (the PDF states "Effective from 2017"); retrieved through a mirror at https://www.itguru.lk/references/ALICTSyllabus2019onwards.pdf |

## How to read it honestly

1. **Topic names are shortened.** The NIE documents describe each competency level in a long sentence inside a table, and the PDF text could not be copied cleanly. Each topic name is a short label derived from the official level title. The official level number is kept in the *reference* column (for example `4.2`), so every topic can be checked against the PDF.
2. **Combined Mathematics comes from a scanned PDF.** Its text had spelling errors; only spelling was corrected. Combined Mathematics I topics carry the competency number (1 to 27). Combined Mathematics II topics carry the competency *group* (1 to 5), because matching each topic to an individual level was not reliable. "Review of basic algebra" has no number.
3. **IT level 1.2's title was cut in the source text.** Its row says so; check it against the syllabus.
4. **The IT PDF was reached through a third-party mirror**, not directly from nie.lk, and the mirror's file name says "2019 onwards" while the document itself says "Effective from 2017". Confirm at nie.lk that it is the version your school follows.
5. **Only the topic structure is included**, not the learning outcomes, content lists or period counts. Use the NIE PDFs and your teachers' guides for detail.
6. The exam syllabus can be revised. If your school follows a newer version, edit the topics on the Syllabus page or import your own file.

## Past papers

No past-paper questions, marks or durations are bundled, because the official per-question marks for ten years of papers were not available from an official source, and the project rule is not to invent exam data. Register papers and questions yourself (or import them with the templates in `database/data/templates/`). The official past papers are published by the Department of Examinations (doenets.lk); only add links and files you are legally allowed to use.

## Sample data

*Settings -> Sample data* can load **made-up** units, papers, marks and logs for demonstration. Everything is labelled **SAMPLE** and can be removed in one click. It is not the real syllabus or real papers.
