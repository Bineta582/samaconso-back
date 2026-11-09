FROM python:3.12-slim
WORKDIR /srv
ENV PYTHONDONTWRITEBYTECODE=1 PYTHONUNBUFFERED=1
COPY requirements.txt .
RUN pip install --no-cache-dir $(grep -v -e pytest -e httpx requirements.txt)
COPY app ./app
RUN useradd -m ml && chown -R ml /srv
USER ml
EXPOSE 8001
HEALTHCHECK CMD python -c "import urllib.request;urllib.request.urlopen('http://127.0.0.1:8001/health')"
CMD ["uvicorn", "app.main:app", "--host", "0.0.0.0", "--port", "8001"]
