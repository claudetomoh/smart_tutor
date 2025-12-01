/* ===========================
   SMARTREGISTER DASHBOARD SCRIPT
   ===========================
   I built this based on the Faculty Intern dashboard requirement.
   It displays courses dynamically and includes a "Join as Observer"
   button for auditors, as discussed in the meeting minutes.
   In a real system, this would connect to a database or API.
*/

// Simulated data (like what would come from a DB)
const courses = [
  { title: "Introduction to Web Tech", instructor: "Dr. Osafo-Maafo", sessions: 2 },
  { title: "Database Systems", instructor: "Prof. Stephane Nwolley", sessions: 1 },
  { title: "WOC", instructor: "Ms. Theodora Aryee", sessions: 8 },
  { title: "Systems Analysis and Design", instructor: "Dr. Dennis Owusu", sessions: 4 }
];

// Select the course grid container
const courseGrid = document.getElementById("courseGrid");

// I learned how to create course cards dynamically using loops.
// This avoids repeating code for each course manually.
courses.forEach((course) => {
  const card = document.createElement("div");
  card.classList.add("course-card");

  // I used template literals to insert course info easily.
  // Added both "Manage Attendance" and "Join as Observer" buttons
  card.innerHTML = `
    <h3>${course.title}</h3>
    <p><strong>Instructor:</strong> ${course.instructor}</p>
    <p><strong>Sessions:</strong> ${course.sessions}</p>
    <div class="card-buttons">
      <button onclick="viewCourse('${course.title}')">Manage Attendance</button>
      <button class="observer-btn" onclick="joinAsObserver('${course.title}')">Join as Observer</button>
    </div>
  `;

  courseGrid.appendChild(card);
});

// Function for managing attendance
function viewCourse(courseTitle) {
  alert(`Opening dashboard for ${courseTitle}`);
}

// Function for joining a course as an observer
// I added this because, during the meeting, it was mentioned
// that staff and students sometimes join as auditors.
function joinAsObserver(courseTitle) {
  alert(`You have joined ${courseTitle} as an observer.`);
}
