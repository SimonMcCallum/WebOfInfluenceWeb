import requests
import json

def call_api_for_names(text_prompt):
    """
    Calls the API with a text prompt and returns a JSON response containing names.

    Args:
        text_prompt (str): The text prompt to send to the API.

    Returns:
        dict: JSON response containing names, or None if error.
    """
    api_url = "http://127.0.0.1:5050/ai/generate"  # Adjust if running on different host/port

    headers = {
        'Content-Type': 'application/json'
    }

    payload = {
        "prompt": text_prompt
    }

    try:
        response = requests.post(api_url, headers=headers, json=payload)

        if response.status_code == 200:
            data = response.json()
            generated_text = data.get('response', '')

            # Clean the response by removing markdown code blocks if present
            import re
            # Remove ```json and ``` markers
            cleaned_text = re.sub(r'```\w*\n?', '', generated_text).strip()

            # Attempt to parse the generated text as JSON (assuming it contains names)
            try:
                names_json = json.loads(cleaned_text)
                return names_json
            except json.JSONDecodeError:
                print("Error: Generated response is not valid JSON")
                print(f"Generated text: {generated_text}")
                return None
        else:
            print(f"API Error: {response.status_code} - {response.text}")
            return None

    except requests.RequestException as e:
        print(f"Request Error: {e}")
        return None

if __name__ == "__main__":
    # Example usage: Prompt the AI to generate names in JSON format
    prompt = "Generate a list of 5 fictional names in the following JSON format: {'names': ['Name1', 'Name2', 'Name3', 'Name4', 'Name5']}"
    result = call_api_for_names(prompt)

    if result:
        print("Generated Names:")
        print(json.dumps(result, indent=2))
    else:
        print("Failed to get names from API")
