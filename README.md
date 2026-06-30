# REDFuzz
RedFuzz is a REDCap External Module that provides fuzzy keyword search across the fields of a project. It is intended for situations where standard REDCap search falls short -- partial spellings, alternate drug names, loose phrasing, and similar retrieval problems that exact-match search cannot handle.

# **RedFuzz v2.0.0**

A lightweight fuzzy‑matching search module for REDCap with synonym expansion, stopword filtering, record‑level grouping, and inline match highlighting.

## **Features**

- **Fuzzy Matching** — Word‑level fuzzy scoring using PHP’s `similar_text()`.
- **Exact Match Priority** — Exact substring matches return **100%** and skip fuzzy scoring.
- **Term Expansion** — Configurable synonym groups (e.g., `tylenol|acetaminophen|paracetamol`).
- **Stopword Filtering** — English stopword removal prevents inflated scores.
- **Multi‑keyword Support** — Comma‑separated keyword input.
- **Visual Highlights** — Inline `<mark>` highlighting of matched terms/synonyms.
- **Smart Grouping** — Results grouped by record, sorted by top score.
- **Field Ranking** — Fields sorted by individual match score.
- **Direct Access** — Each result includes a link to the record’s data‑entry page.
- **Configurable Thresholds** — Project‑level default threshold, overridable per search.

---

## **File Structure**

```
redfuzz_v2.0.0/
├── config.json       # Module metadata, project settings, navigation link
├── RedFuzz.php       # Module class (minimal; logic lives in search.php)
└── pages/
    └── search.php    # Full search UI and scoring engine
```

---

## **Installation**

1. **Extract**  
   Ensure the folder name is exactly `redfuzz_v2.0.0` (lowercase, no spaces).  
   REDCap EM folder names are case‑sensitive and must match `config.json`.

2. **Upload**  
   Place the folder into your REDCap external modules directory:
   ```
   /redcap/modules/
   ```

3. **Enable**  
   In REDCap:  
   **Control Center → External Modules → Manage → Enable RedFuzz**

4. **Activate**  
   Enable the module on any project where you want to use it.

5. **Clean Up**  
   If replacing a previous installation with a different folder name, manually remove the old database registration to avoid stale entries.

---

## **Project Settings**

### **1. Synonym Map**

Define synonym groups, one per line.  
Each line is a pipe‑separated list of equivalent terms.

Example:

```
tylenol|acetaminophen|paracetamol
metformin|glucophage
bp|blood pressure
```

Any keyword matching a term in a group automatically expands to all terms in that group.  
Search results display which synonym produced the match.

---

### **2. Default Match Threshold (%)**

- Integer **1–100**
- Minimum similarity score required for a field value to appear in results
- Defaults to **80** if not set
- Users may override per search

---

## **Usage**

1. Open a project with RedFuzz enabled.
2. Click **RedFuzz** in the left navigation under *External Modules*.
3. Enter keywords (comma‑separated).
4. Adjust threshold (optional).
5. Select fields or search all fields.
6. Run search.

Each record block displays:

- Link to data‑entry page  
- Count of matched fields  
- Top score for the record  
- Per‑field details:
  - Label  
  - Variable Name  
  - Score  
  - Term/Synonym  
  - Highlighted Value  

---

## **Scoring Logic**

For each keyword:

- Field values are tokenized on whitespace and punctuation.
- The full value string is included to support multi‑word phrase matching.

For each candidate–synonym pair:

1. **Exact Substring Match** → Score = **100%**, short‑circuit  
2. **Fuzzy Match** → Uses `similar_text()` to compute similarity %

The **highest score** across all candidates/synonyms is used.  
Records and fields are sorted by score (descending).

---

## **Notes & Limitations**

- **Performance** — Uses `REDCap::getData()` without filters; large projects should restrict fields.  
- **Field Types** — Values treated as strings; checkbox values searched as stored (`1`).  
- **Skip Types** — Calculated fields, file uploads, and empty fields are skipped.  
- **Stopwords** — English‑only, fixed list.  
- **No AJAX** — Full page POST + reload.  
- **Events** — Searches across all events; fields in multiple events scored independently.  
- **Security** — Includes `redcap_csrf_token` via `System::getCsrfToken()` (REDCap 17.x compatible).

---

## **Changelog**

### **v2.0.0**

- Initial versioned release  
- Synonym map with full group expansion  
- Exact substring short‑circuit scoring  
- Stopword filtering  
- Match highlighting  
- Record‑grouped results with direct data‑entry links  
- Configurable default threshold  
