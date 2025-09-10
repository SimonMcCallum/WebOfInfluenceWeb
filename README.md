## Web Of Influence Research Project
**Current Progress** <br>
- The project can currently:
- Load Candidate Donation Overviews for the 2011, 2014, 2017, and 2023 elections from the provided datasets.
- Provide a local API for querying the database.
- Serve a React-based frontend with search and filtering functionality.
- Export search results to a .csv file.


#### How to run the code
1. Update the user and password access in ```.env```
2. python3 TestDbLoader/database_api.py
3. python3 TestDbLoader/run.py
4. In a new terminal, change into the directory demo-cand
5. Use the command **npm run dev**